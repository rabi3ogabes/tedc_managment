<?php

namespace App\Services;

use App\Models\ClassroomObservation;
use App\Models\Employee;
use App\Models\NeedsRule;
use App\Models\PerformanceAppraisal;
use App\Models\User;
use Illuminate\Support\Collection;

/** Turns appraisals, observations, new hires and specialisation into explained needs. Idempotent: re-running never duplicates. */
class NeedsRuleEngine
{
    public function __construct(private readonly IndividualNeedsService $needs, private readonly NotificationService $notifications) {}

    /** @return array{rules: int, created: int} */
    public function run(?NeedsRule $only = null): array
    {
        $rules = $only ? collect([$only]) : NeedsRule::where('is_active', true)->orderBy('sort_order')->get();
        $created = [];
        foreach ($rules as $rule) {
            foreach ($this->matches($rule) as [$employee, $ref, $ar, $en, $current]) {
                foreach ($rule->action['skill_ids'] ?? [] as $skillId) {
                    $required = (int) ($rule->action['required_level'] ?? 3);
                    $need = $this->needs->upsert($employee, $skillId, $rule->trigger, [
                        'source_ref' => $ref + ['rule_id' => $rule->id], 'current_level' => $current, 'required_level' => $required, 'priority_score' => ($required - ($current ?? 0)) * 10 * (1 + (float) ($rule->action['priority_boost'] ?? 0)),
                        'explanation_ar' => $ar, 'explanation_en' => $en, 'status' => ($rule->action['auto_approve'] ?? false) ? 'auto_approved' : 'pending_manager',
                    ]);
                    $need && $created[] = $need;
                }
            }
            $rule->update(['last_run_at' => now()]);
        }
        $this->needs->notifyManagers(array_filter($created, fn ($n) => $n->status === 'pending_manager'));
        if ($created) {
            $planners = User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'needs.cycles'))->pluck('id')->all();
            $n = count($created);
            $planners && $this->notifications->broadcast($planners, 'needs.rules_generated', ['ar' => 'احتياجات جديدة مولّدة آلياً', 'en' => 'New needs were generated automatically'],
                ['ar' => "ولّدت القواعد {$n} احتياجاً جديداً.", 'en' => "The rules generated {$n} new need(s)."], ['detail_ar' => "ولّدت القواعد {$n} احتياجاً جديداً.", 'detail_en' => "The rules generated {$n} new need(s)."]);
        }

        return ['rules' => $rules->count(), 'created' => count($created)];
    }

    /** @return iterable<array{0: Employee, 1: array, 2: string, 3: string, 4: ?int}> */
    private function matches(NeedsRule $rule): iterable
    {
        $c = $rule->conditions ?? [];

        return match ($rule->trigger) {
            'new_hire' => Employee::whereNotNull('hire_date')->where('hire_date', '>=', today()->subMonths((int) ($c['months'] ?? 6)))->get()->map(fn ($e) => [$e, ['hire_date' => $e->hire_date->toDateString()],
                'موظف جديد (تعيين منذ أقل من '.($c['months'] ?? 6).' أشهر): برنامج تأهيل.', 'New hire (hired within '.($c['months'] ?? 6).' months): induction need.', null]),
            'appraisal' => PerformanceAppraisal::whereIn('year', $c['years'] ?? [now()->year - 1])->whereIn('rating_code', $c['ratings'] ?? ['weak', 'acceptable'])->with('employee')->get()->filter(fn ($a) => $a->employee)
                ->map(fn ($a) => [$a->employee, ['appraisal_id' => $a->id, 'year' => $a->year], "تقييم الأداء {$a->year}: {$a->rating_code}.", "Appraisal {$a->year}: {$a->rating_code}.", null]),
            'observation' => $this->observations($rule, $c),
            'specialisation', 'stage' => Employee::query()->when($c['specializations'] ?? null, fn ($q, $v) => $q->whereIn('specialization', $v))->when($c['stages'] ?? null, fn ($q, $v) => $q->whereIn('education_stage', $v))
                ->when(! ($c['specializations'] ?? null) && ! ($c['stages'] ?? null), fn ($q) => $q->whereRaw('1 = 0'))->get()
                ->map(fn ($e) => [$e, ['specialization' => $e->specialization, 'stage' => $e->education_stage], 'مطلوب لتخصصك أو مرحلتك التعليمية.', 'Required for your specialisation or education stage.', null]),
            default => [],   // licence and test triggers are fed by Phases 06 and 09
        };
    }

    private function observations(NeedsRule $rule, array $c): Collection
    {
        $below = (int) ($c['below_level'] ?? 3);
        $skills = $rule->action['skill_ids'] ?? [];
        $out = collect();
        foreach (ClassroomObservation::where('observed_on', '>=', today()->subYears((int) ($c['years'] ?? 2)))->with('employee')->get() as $o) {
            foreach ($skills as $skillId) {
                $score = $o->scores[$skillId] ?? null;
                if ($o->employee && $score !== null && $score < $below) {
                    $out->push([$o->employee, ['observation_id' => $o->id, 'score' => $score], "الملاحظة الصفية بتاريخ {$o->observed_on->toDateString()}: مستوى {$score} أقل من {$below}.", "Classroom observation on {$o->observed_on->toDateString()}: level {$score} is below {$below}.", (int) $score]);
                }
            }
        }

        return $out->unique(fn ($m) => $m[0]->id)->values();
    }
}
