<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CareerPath;
use App\Models\CareerPathLevel;
use App\Models\Employee;
use App\Models\EmployeePathProgress;
use App\Models\ProfessionalLicence;
use App\Models\Program;
use App\Models\User;
use App\Services\Eligibility\EligibilityEngine;
use App\Services\Eligibility\EmployeeContext;
use Illuminate\Support\Str;

/**
 * Evaluates employees against promotion and licence paths level by level. Conditions use the eligibility-rule syntax,
 * so the same fields (experience, grade, appraisal, completed programs, PD hours, licence level …) work everywhere, and
 * a program can require `licence_level >= 2` or `path_level >= 1`. A change that gains or loses a level is told once.
 */
class CareerPathEngine
{
    public function __construct(private readonly EligibilityEngine $rules, private readonly NotificationService $notifications, private readonly RegistrationService $registrations) {}

    /** @return list<EmployeePathProgress> */
    public function evaluate(Employee $employee): array
    {
        $employee->loadMissing('user', 'jobTitle');
        $context = EmployeeContext::fromEmployee($employee);
        $out = [];
        foreach (CareerPath::with('levels')->where('is_active', true)->get() as $path) {
            if (! empty($path->job_title_ids) && ! in_array($employee->job_title_id, $path->job_title_ids, true)) {
                continue;
            }
            $out[] = $this->evaluatePath($employee, $path, $context);
        }

        return $out;
    }

    public function evaluatePath(Employee $employee, CareerPath $path, ?EmployeeContext $context = null): EmployeePathProgress
    {
        $context ??= EmployeeContext::fromEmployee($employee);
        $row = EmployeePathProgress::firstOrNew(['employee_id' => $employee->id, 'path_id' => $path->id]);
        $before = $row->status;
        $licence = $path->type === 'licence' ? ProfessionalLicence::where('employee_id', $employee->id)->where('path_id', $path->id)->orderByDesc('level_no')->get() : collect();
        $active = $licence->first(fn ($l) => $l->status === 'active' && (! $l->expires_at || $l->expires_at->gte(today())));
        $current = $path->type === 'licence' ? (int) ($active?->level_no ?? 0) : (int) ($row->current_level_no ?? 0);
        $next = $path->levels->first(fn (CareerPathLevel $l) => $l->level_no > $current);

        $explanation = [];
        if ($next) {
            $explanation = $this->check($next, $context);
            $met = count(array_filter($explanation, fn ($c) => $c['met']));
            $status = $met === count($explanation) ? 'eligible' : ($met > 0 || $current > 0 ? 'in_progress' : 'not_started');
        } else {
            $status = 'achieved';
        }
        if ($path->type === 'licence' && ! $active && $licence->isNotEmpty() && $current === 0) {
            $status = 'expired';
        }

        $row->fill(['current_level_no' => $current, 'target_level_no' => $next?->level_no, 'status' => $status, 'explanation' => $explanation, 'evaluated_at' => now()])->save();

        if ($status === 'eligible' && $before !== 'eligible') {
            $this->tell($employee, 'path.eligible', ['ar' => 'أصبحت مؤهلاً للمستوى التالي', 'en' => 'You are eligible for the next level'], "استوفيت شروط «{$next->title_ar}» في مسار «{$path->title_ar}».", "You meet the conditions of \"{$next->title_en}\" in \"{$path->title_en}\".", $path->id);
        } elseif ($before === 'eligible' && $status === 'in_progress') {
            $this->tell($employee, 'path.condition_lost', ['ar' => 'لم يعد أحد الشروط مستوفى', 'en' => 'A condition is no longer met'], "تغيّر وضعك في مسار «{$path->title_ar}»: لم تعد مؤهلاً للمستوى التالي.", "Your status in \"{$path->title_en}\" changed: you are no longer eligible for the next level.", $path->id);
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function check(CareerPathLevel $level, EmployeeContext $ctx): array
    {
        $checks = [];
        foreach ($level->conditions ?? [] as $i => $c) {
            $met = $this->rules->passes($c['field'], $c['operator'], $c['value'] ?? null, $ctx);
            $checks[] = ['key' => "condition.$i", 'type' => 'condition', 'field' => $c['field'], 'label' => __("eligibility.fields.{$c['field']}"), 'operator' => $c['operator'], 'expected' => $c['value'] ?? null, 'actual' => $this->actual($ctx, $c['field']), 'met' => $met];
        }
        foreach ($level->required_programs ?? [] as $i => $g) {
            $codes = Program::whereIn('id', $g['program_ids'] ?? [])->pluck('code')->all();
            $done = count(array_intersect($codes, $ctx->equivalentCompleted));
            $min = (int) ($g['min'] ?? count($codes));
            $checks[] = ['key' => "programs.$i", 'type' => 'programs', 'label' => __('messages.career.programs', ['min' => $min, 'total' => count($codes)]), 'expected' => $min, 'actual' => $done, 'met' => $done >= $min, 'programs' => $codes];
        }
        if ((float) $level->min_pd_hours > 0) {
            $checks[] = ['key' => 'pd_hours', 'type' => 'pd_hours', 'label' => __('eligibility.fields.pd_hours'), 'expected' => (float) $level->min_pd_hours, 'actual' => (float) ($ctx->pdHours ?? 0), 'met' => ($ctx->pdHours ?? 0) >= (float) $level->min_pd_hours];
        }

        return $checks;
    }

    private function actual(EmployeeContext $ctx, string $field): mixed
    {
        $v = $ctx->value($field);

        return is_scalar($v) || $v === null ? $v : (is_array($v) ? count($v) : null);
    }

    /** Records the achievement of the target level: a licence is issued, a promotion level is stored. */
    public function achieve(Employee $employee, CareerPath $path, User $by): EmployeePathProgress
    {
        $employee->loadMissing('user');
        $row = $this->evaluatePath($employee, $path);
        if ($row->status !== 'eligible') {
            throw new BusinessRuleException(__('messages.career.not_eligible'), 'not_eligible');
        }
        $level = $path->levels->firstWhere('level_no', $row->target_level_no);
        if ($path->type === 'licence') {
            ProfessionalLicence::create(['employee_id' => $employee->id, 'path_id' => $path->id, 'level_no' => $level->level_no, 'licence_no' => strtoupper('LIC-'.Str::random(8)), 'issued_at' => today(), 'expires_at' => $level->validity_months ? today()->addMonths($level->validity_months) : null, 'status' => 'active', 'source' => 'platform']);
        } else {
            $row->update(['current_level_no' => $level->level_no]);
        }
        $row = $this->evaluatePath($employee->refresh(), $path);
        $this->tell($employee, 'path.achieved', ['ar' => 'حققت مستوى جديداً', 'en' => 'You reached a new level'], "تهانينا: بلغت «{$level->title_ar}» في مسار «{$path->title_ar}».", "Congratulations: you reached \"{$level->title_en}\" in \"{$path->title_en}\".", $path->id);

        return $row;
    }

    /** Nightly: everyone against every path. */
    public function evaluateAll(): int
    {
        $n = 0;
        Employee::whereNotNull('user_id')->chunkById(200, function ($employees) use (&$n) {
            foreach ($employees as $e) {
                $this->evaluate($e);
                $n++;
            }
        });

        return $n;
    }

    private function tell(Employee $e, string $event, array $title, string $ar, string $en, string $pathId): void
    {
        $users = array_filter([$e->user_id, $this->registrations->resolveManager($e)?->id]);
        foreach (array_unique($users) as $uid) {
            $who = $uid === $e->user_id ? '' : ($e->user?->displayName() ?? '').': ';
            $this->notifications->send($uid, $event, $title, ['ar' => $who.$ar, 'en' => $who.$en], ['path_id' => $pathId, 'employee_id' => $e->id]);
        }
    }
}
