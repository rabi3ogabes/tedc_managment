<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\IndividualNeed;
use App\Models\PerformanceAppraisal;
use App\Models\Program;
use App\Models\Registration;
use App\Models\RegistrationPriorityRule;
use App\Models\TrainingGroup;
use Illuminate\Support\Carbon;

/** Ranks candidates and the waiting list from ordered, weighted criteria, with a human explanation per person. */
class RegistrationPriorityService
{
    public const DEFAULT_CRITERIA = ['plan_targeted', 'approved_individual_need', 'no_training_in_months', 'appraisal_below', 'entity_priority', 'registration_date'];

    public const DEFAULT_WEIGHTS = ['plan_targeted' => 30, 'approved_individual_need' => 30, 'no_training_in_months' => 20, 'appraisal_below' => 10, 'entity_priority' => 5, 'registration_date' => 5, 'job_title_in' => 10];

    /** The rule that applies: the group's, else the program's, else the global one, else the RFP defaults. @return array{criteria: list<string>, weights: array<string, float>, job_title_ids: list<string>} */
    public function ruleFor(Program $program, ?TrainingGroup $group): array
    {
        $rule = RegistrationPriorityRule::where('is_active', true)->where(fn ($q) => $q->where(fn ($w) => $w->where('scope', 'group')->where('scope_id', $group?->id))
            ->orWhere(fn ($w) => $w->where('scope', 'program')->where('scope_id', $program->id))->orWhere('scope', 'global'))
            ->get()->sortBy(fn ($r) => ['group' => 0, 'program' => 1, 'global' => 2][$r->scope])->first();

        return [
            'criteria' => $rule->criteria ?? self::DEFAULT_CRITERIA,
            'weights' => array_replace(self::DEFAULT_WEIGHTS, $rule?->weights ?? []),
            'job_title_ids' => $rule?->weights['job_title_ids'] ?? [],
        ];
    }

    /** Merges a rule given by the preview screen with the default weights. @return array{criteria: list<string>, weights: array<string, float>, job_title_ids: list<string>} */
    public function normalize(array $criteria, ?array $weights): array
    {
        return ['criteria' => array_values(array_intersect($criteria, array_merge(self::DEFAULT_CRITERIA, ['job_title_in']))), 'weights' => array_replace(self::DEFAULT_WEIGHTS, $weights ?? []), 'job_title_ids' => $weights['job_title_ids'] ?? []];
    }

    /** @return array{score: float, explanation: list<array{criterion: string, points: float, ar: string, en: string}>} */
    public function score(Employee $employee, Program $program, ?TrainingGroup $group, ?float $entityPriority = null, ?array $ruleOverride = null): array
    {
        $rule = $ruleOverride ?? $this->ruleFor($program, $group);
        $w = $rule['weights'];
        $lines = [];
        $add = function (string $c, float $points, string $ar, string $en) use (&$lines) {
            if ($points > 0) {
                $lines[] = ['criterion' => $c, 'points' => round($points, 2), 'ar' => $ar, 'en' => $en];
            }
        };

        foreach ($rule['criteria'] as $c) {
            switch ($c) {
                case 'plan_targeted':
                    $item = $group?->planItem;
                    $audience = $item?->audience['job_title_ids'] ?? null;
                    if ($item && (! $audience || in_array($employee->job_title_id, $audience, true))) {
                        $add($c, $w[$c], 'البرنامج مستهدف في الخطة التدريبية.', 'The program is targeted in the training plan.');
                    }
                    break;
                case 'approved_individual_need':
                    $skills = $program->skills()->pluck('skills.id');
                    if ($skills->isNotEmpty() && IndividualNeed::where('employee_id', $employee->id)->whereIn('skill_id', $skills)->whereIn('status', ['approved', 'auto_approved'])->exists()) {
                        $add($c, $w[$c], 'لديه احتياج معتمد يغطيه هذا البرنامج.', 'Has an approved need this program covers.');
                    }
                    break;
                case 'no_training_in_months':
                    $last = Registration::where('employee_id', $employee->id)->where('status', Registration::STATUS_COMPLETED)->max('completed_at');
                    $months = $last ? Carbon::parse($last)->diffInMonths(now()) : 24;
                    $add($c, $w[$c] * min(1, $months / 12), $last ? "لم يتدرب منذ {$months} شهراً." : 'لم يسبق له التدرب.', $last ? "No training for {$months} month(s)." : 'Has never trained.');
                    break;
                case 'appraisal_below':
                    $rating = PerformanceAppraisal::where('employee_id', $employee->id)->orderByDesc('year')->value('rating_code');
                    if (in_array($rating, ['weak', 'acceptable'], true)) {
                        $add($c, $w[$c], 'تقييم الأداء الأخير يحتاج إلى دعم.', 'Latest appraisal calls for support.');
                    }
                    break;
                case 'job_title_in':
                    if (in_array($employee->job_title_id, $rule['job_title_ids'], true)) {
                        $add($c, $w[$c], 'من الوظائف ذات الأولوية.', 'Holds a priority job title.');
                    }
                    break;
                case 'entity_priority':
                    $add($c, $w[$c] * min(1, ($entityPriority ?? 0) / 10), 'أولوية الجهة المستفيدة.', 'Priority of the beneficiary entity.');
                    break;
            }
        }

        return ['score' => round(array_sum(array_column($lines, 'points')), 2), 'explanation' => $lines];
    }
}
