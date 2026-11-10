<?php

namespace App\Services;

use App\Models\ClassroomObservation;
use App\Models\Employee;
use App\Models\JobCompetencyRequirement;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\School;
use App\Models\Skill;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanItem;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Collection;

/**
 * Required vs current competency level per employee, aggregated by skill, school, job title or region and ranked
 * (gap × people affected × licence weight), with the gaps no program covers yet.
 */
class GapAnalysisService
{
    public const GROUPS = ['skill', 'school', 'job_title', 'region'];

    public function __construct(private readonly CompetencyService $competencies) {}

    /**
     * @param  array{school_id?: ?string, job_title_id?: ?string, skill_id?: ?string}  $filters
     * @return Collection<int, array<string, mixed>> one row per employee × required competency with a gap
     */
    public function gapRows(User $user, array $filters = []): Collection
    {
        $requirements = JobCompetencyRequirement::all()->groupBy('job_title_id');
        if ($requirements->isEmpty()) {
            return collect();
        }
        $rows = collect();
        $query = AccessScope::current($user)->constrainEmployees(Employee::query()->with('school:id,region,name_ar,name_en')->whereIn('job_title_id', $requirements->keys()))
            ->when($filters['school_id'] ?? null, fn ($q, $v) => $q->where('school_id', $v))->when($filters['job_title_id'] ?? null, fn ($q, $v) => $q->where('job_title_id', $v));

        $query->chunkById(500, function ($employees) use ($requirements, $filters, &$rows) {
            $ids = $employees->pluck('id');
            $skills = \DB::table('employee_skills')->whereIn('employee_id', $ids)->get()->groupBy('employee_id');
            $observations = ClassroomObservation::whereIn('employee_id', $ids)->where('observed_on', '>=', today()->subYears(2))->get()->groupBy('employee_id');
            foreach ($employees as $employee) {
                $jobRows = $requirements->get($employee->job_title_id, collect());
                $own = ($skills->get($employee->id) ?? collect())->map(fn ($r) => (object) ['id' => $r->skill_id, 'pivot' => (object) ['level' => $r->level, 'source' => $r->source, 'verified_at' => $r->verified_at]]);
                foreach ($jobRows->pluck('skill_id')->unique() as $skillId) {
                    if (($filters['skill_id'] ?? $skillId) !== $skillId) {
                        continue;
                    }
                    $req = $this->competencies->requirementFor($employee, $skillId, $jobRows);
                    if (! $req) {
                        continue;
                    }
                    $current = $this->competencies->currentLevel($employee, $skillId, $observations->get($employee->id, collect()), $own);
                    $gap = CompetencyService::gap($current['level'], $req->required_level);
                    $rows->push(['employee_id' => $employee->id, 'skill_id' => $skillId, 'school_id' => $employee->school_id, 'school' => $employee->school, 'job_title_id' => $employee->job_title_id,
                        'region' => $employee->school?->region, 'required' => $req->required_level, 'current' => $current['level'], 'gap' => $gap, 'weight' => $req->weight]);
                }
            }
        });

        return $rows;
    }

    /** @return array{rows: list<array<string, mixed>>, uncovered: list<array<string, mixed>>, totals: array<string, int>} */
    public function analyse(User $user, string $groupBy = 'skill', array $filters = []): array
    {
        $all = $this->gapRows($user, $filters);
        $gapped = $all->where('gap', '>', 0);
        $skills = Skill::whereIn('id', $all->pluck('skill_id')->unique())->get()->keyBy('id');
        $covered = $this->coveredSkillIds();
        $licenceWeight = $this->competencies->weights()['licence'];
        $labels = $this->labels($groupBy, $gapped);

        $rows = $gapped->groupBy(fn ($r) => $r[$this->column($groupBy)] ?? 'none')->map(function (Collection $group, $key) use ($groupBy, $all, $skills, $covered, $licenceWeight, $labels) {
            $employees = $group->pluck('employee_id')->unique()->count();
            $avg = round($group->avg('gap'), 2);
            $licence = $group->contains(fn ($r) => $skills->get($r['skill_id'])?->licence_relevant);
            $priority = round($avg * $employees * ($licence ? $licenceWeight : 1), 2);
            $evaluated = $all->filter(fn ($r) => ($r[$this->column($groupBy)] ?? 'none') === $key)->pluck('employee_id')->unique()->count();
            $uncovered = $groupBy === 'skill' && ! in_array($key, $covered, true);

            return [
                'key' => $key, 'label_ar' => $labels[$key]['ar'] ?? (string) $key, 'label_en' => $labels[$key]['en'] ?? (string) $key,
                'employees' => $employees, 'evaluated' => $evaluated, 'avg_gap' => $avg, 'priority' => $priority, 'licence_relevant' => $licence, 'uncovered' => $uncovered,
                'explanation_ar' => "{$employees} من {$evaluated} موظفاً دون المستوى المطلوب بمتوسط فجوة {$avg}".($licence ? ' — مرتبط بالترخيص' : '').($uncovered ? ' — لا يوجد برنامج يغطيه' : '').'.',
                'explanation_en' => "{$employees} of {$evaluated} employees are below the required level, average gap {$avg}".($licence ? ' — licence-relevant' : '').($uncovered ? ' — no program covers it' : '').'.',
            ];
        })->sortByDesc('priority')->values();

        return ['rows' => $rows->all(), 'uncovered' => $rows->where('uncovered', true)->values()->all(), 'totals' => ['employees' => $gapped->pluck('employee_id')->unique()->count(), 'gaps' => $gapped->count()]];
    }

    public function employeeDetail(Employee $employee): array
    {
        $rows = JobCompetencyRequirement::where('job_title_id', $employee->job_title_id)->get();
        $skills = Skill::with('domain')->whereIn('id', $rows->pluck('skill_id')->unique())->get()->keyBy('id');
        $observations = ClassroomObservation::where('employee_id', $employee->id)->where('observed_on', '>=', today()->subYears(2))->get();
        $own = $employee->skills()->withPivot(['level', 'source', 'verified_at'])->get();

        $competencies = $rows->pluck('skill_id')->unique()->map(function ($skillId) use ($employee, $rows, $skills, $observations, $own) {
            $req = $this->competencies->requirementFor($employee, $skillId, $rows);
            $cur = $this->competencies->currentLevel($employee, $skillId, $observations, $own);

            return ['skill_id' => $skillId, 'name_ar' => $skills[$skillId]->name_ar, 'name_en' => $skills[$skillId]->name_en, 'domain' => $skills[$skillId]->domain?->name_en,
                'required_level' => $req?->required_level, 'current_level' => $cur['level'], 'gap' => $req ? CompetencyService::gap($cur['level'], $req->required_level) : 0, 'evidence' => $cur['evidence']];
        })->values();

        return ['employee_id' => $employee->id, 'competencies' => $competencies->all()];
    }

    /**
     * Adds plan items for the chosen skills (idempotent by skill).
     *
     * @param  list<string>  $skillIds
     * @return array{added: int}
     */
    public function toPlan(User $user, TrainingPlan $plan, array $skillIds): array
    {
        $analysis = collect($this->analyse($user, 'skill')['rows'])->whereIn('key', $skillIds);
        $existing = $plan->items()->pluck('source_refs')->flatten()->filter()->flip();
        $added = 0;
        foreach ($analysis as $row) {
            if ($existing->has($row['key'])) {
                continue;
            }
            $score = $row['priority'];
            TrainingPlanItem::create([
                'plan_id' => $plan->id, 'title_ar' => $row['label_ar'], 'title_en' => $row['label_en'], 'priority' => $score >= 30 ? 'critical' : ($score >= 15 ? 'high' : ($score >= 5 ? 'medium' : 'low')),
                'priority_score' => min(999, $score), 'planned_groups' => max(1, (int) ceil($row['employees'] / 25)), 'planned_seats' => $row['employees'], 'planned_hours' => max(1, (int) ceil($row['employees'] / 25)) * 12,
                'source' => 'needs', 'source_refs' => [$row['key']], 'rationale_ar' => $row['explanation_ar'], 'rationale_en' => $row['explanation_en'],
            ]);
            $added++;
        }

        return ['added' => $added];
    }

    /** @return list<string> */
    private function coveredSkillIds(): array
    {
        return Program::whereNotIn('status', [Program::STATUS_CANCELLED, Program::STATUS_ARCHIVED])->with('skills:id')->get()->pluck('skills')->flatten()->pluck('id')->unique()->values()->all();
    }

    private function column(string $groupBy): string
    {
        return ['skill' => 'skill_id', 'school' => 'school_id', 'job_title' => 'job_title_id', 'region' => 'region'][$groupBy] ?? 'skill_id';
    }

    /** @return array<string, array{ar: string, en: string}> */
    private function labels(string $groupBy, Collection $rows): array
    {
        $keys = $rows->pluck($this->column($groupBy))->unique()->filter()->values();

        return match ($groupBy) {
            'skill' => Skill::whereIn('id', $keys)->get()->mapWithKeys(fn ($s) => [$s->id => ['ar' => $s->name_ar, 'en' => $s->name_en]])->all(),
            'school' => School::whereIn('id', $keys)->get()->mapWithKeys(fn ($s) => [$s->id => ['ar' => $s->name_ar, 'en' => $s->name_en]])->all(),
            'job_title' => JobTitle::whereIn('id', $keys)->get()->mapWithKeys(fn ($j) => [$j->id => ['ar' => $j->name_ar, 'en' => $j->name_en]])->all(),
            default => $keys->mapWithKeys(fn ($k) => [$k => ['ar' => (string) $k, 'en' => (string) $k]])->all(),
        };
    }
}
