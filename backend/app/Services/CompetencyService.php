<?php

namespace App\Services;

use App\Models\ClassroomObservation;
use App\Models\CompetencyDomain;
use App\Models\Employee;
use App\Models\JobCompetencyRequirement;
use App\Models\SiteSetting;
use App\Models\Skill;
use Illuminate\Support\Collection;

/** Competency framework: required levels per job, and each employee's current level blended from the evidence we hold. */
class CompetencyService
{
    public const DEFAULT_WEIGHTS = ['verified' => 3, 'manager' => 3, 'observation' => 2, 'self' => 1, 'licence' => 1.5];

    public function weights(): array
    {
        return array_replace(self::DEFAULT_WEIGHTS, SiteSetting::find('competency.weights')?->value ?? []);
    }

    public function saveWeights(array $weights): array
    {
        SiteSetting::updateOrCreate(['key' => 'competency.weights'], ['value' => array_replace($this->weights(), $weights), 'updated_by' => auth()->id()]);

        return $this->weights();
    }

    /**
     * The requirement that applies to an employee for a skill: the most specific match
     * (stage + subject, then stage, then subject, then the job in general).
     *
     * @param  Collection<int, JobCompetencyRequirement>  $rows  requirements of the employee's job
     */
    public function requirementFor(Employee $employee, string $skillId, Collection $rows): ?JobCompetencyRequirement
    {
        $stage = $employee->education_stage;
        $subject = $employee->specialization ? mb_strtolower($employee->specialization) : null;

        return $rows->where('skill_id', $skillId)->map(function (JobCompetencyRequirement $r) use ($stage, $subject) {
            $stageOk = $r->education_stage === null || $r->education_stage === $stage;
            $subjectOk = $r->subject === null || ($subject !== null && mb_strtolower($r->subject) === $subject);

            return $stageOk && $subjectOk ? [$r, ($r->education_stage ? 2 : 0) + ($r->subject ? 1 : 0)] : null;
        })->filter()->sortByDesc(fn ($pair) => $pair[1])->map(fn ($pair) => $pair[0])->first();
    }

    /**
     * Current level and the evidence behind it.
     *
     * @param  Collection<int, Collection<int, mixed>>|null  $observations  pre-loaded observations of the employee (last two years)
     * @return array{level: float|null, evidence: list<array<string, mixed>>}
     */
    public function currentLevel(Employee $employee, string $skillId, ?Collection $observations = null, ?Collection $employeeSkills = null): array
    {
        $w = $this->weights();
        $evidence = [];
        $skill = ($employeeSkills ?? $employee->skills()->withPivot(['level', 'source', 'verified_at'])->get())->firstWhere('id', $skillId);

        if ($skill) {
            $verified = $skill->pivot->verified_at !== null;
            $type = $verified ? 'verified' : ($skill->pivot->source === 'supervisor' ? 'manager' : 'self');
            $evidence[] = ['type' => $type, 'level' => (float) $skill->pivot->level, 'weight' => $w[$type], 'source' => $skill->pivot->source];
        }

        $observations ??= ClassroomObservation::where('employee_id', $employee->id)->where('observed_on', '>=', today()->subYears(2))->get();
        $scores = $observations->map(fn ($o) => $o->scores[$skillId] ?? null)->filter(fn ($v) => $v !== null);
        if ($scores->isNotEmpty()) {
            $evidence[] = ['type' => 'observation', 'level' => round($scores->avg(), 2), 'weight' => $w['observation'], 'count' => $scores->count()];
        }

        $sum = array_sum(array_map(fn ($e) => $e['weight'], $evidence));
        $level = $sum > 0 ? round(array_sum(array_map(fn ($e) => $e['level'] * $e['weight'], $evidence)) / $sum, 2) : null;

        return ['level' => $level, 'evidence' => $evidence];
    }

    /** Gap size from a (possibly fractional) current level: rounded to whole levels, never negative; no evidence counts as level 0. */
    public static function gap(?float $current, int $required): int
    {
        return max(0, $required - (int) round($current ?? 0));
    }

    public function replaceRequirements(string $jobTitleId, array $rows): Collection
    {
        JobCompetencyRequirement::where('job_title_id', $jobTitleId)->delete();
        foreach ($rows as $r) {
            JobCompetencyRequirement::create(['job_title_id' => $jobTitleId, 'skill_id' => $r['skill_id'], 'education_stage' => $r['education_stage'] ?? null, 'subject' => $r['subject'] ?? null, 'required_level' => $r['required_level'], 'weight' => $r['weight'] ?? 1]);
        }

        return JobCompetencyRequirement::where('job_title_id', $jobTitleId)->get();
    }

    /** @return array{created: int, updated: int, errors: list<array{line: int, reason: string}>} */
    public function import(array $rows): array
    {
        $created = $updated = 0;
        $errors = [];
        $domains = CompetencyDomain::pluck('id', 'code');
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '' && trim(implode('', $row)) === '') {
                $errors[] = ['line' => $line, 'reason' => 'empty'];

                continue;
            }
            if ($code === '' || trim((string) ($row['name_ar'] ?? '')) === '' || trim((string) ($row['name_en'] ?? '')) === '') {
                $errors[] = ['line' => $line, 'reason' => 'code, name_ar and name_en are required'];

                continue;
            }
            $domainCode = trim((string) ($row['domain'] ?? ''));
            $domainId = null;
            if ($domainCode !== '') {
                $domainId = $domains[$domainCode] ?? ($domains[$domainCode] = CompetencyDomain::create(['code' => $domainCode, 'name_ar' => $domainCode, 'name_en' => $domainCode])->id);
            }
            $descriptors = [];
            foreach (range(1, 5) as $lvl) {
                $ar = trim((string) ($row["level_{$lvl}_ar"] ?? ''));
                $en = trim((string) ($row["level_{$lvl}_en"] ?? ''));
                if ($ar !== '' || $en !== '') {
                    $descriptors[$lvl] = ['ar' => $ar, 'en' => $en];
                }
            }
            $skill = Skill::firstOrNew(['code' => $code]);
            $isNew = ! $skill->exists;
            $skill->fill(['name_ar' => $row['name_ar'], 'name_en' => $row['name_en'], 'category' => ($row['category'] ?? '') ?: ($skill->category ?: 'general'), 'domain_id' => $domainId ?? $skill->domain_id,
                'licence_relevant' => in_array(strtolower((string) ($row['licence_relevant'] ?? '')), ['1', 'true', 'yes', 'نعم'], true), 'descriptors' => $descriptors ?: $skill->descriptors])->save();
            $isNew ? $created++ : $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }
}
