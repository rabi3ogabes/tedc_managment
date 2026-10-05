<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\KnowledgeTransfer;
use App\Models\PdActivity;
use App\Models\PdAnnualTarget;
use App\Models\SiteSetting;
use Carbon\Carbon;

/** Professional-development hours per employee and year, from every source, against the annual minimum. */
class AnnualHoursService
{
    public const SOURCES = ['center', 'internal', 'external', 'knowledge_transfer'];

    public function __construct(private readonly NotificationService $notifications) {}

    /** The first month of the counting year (1 = calendar year; 9 = a fiscal year that starts in September). */
    public function startMonth(): int
    {
        return max(1, min(12, (int) (SiteSetting::find('pd')?->value['year_start_month'] ?? 1)));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function bounds(int $year): array
    {
        $m = $this->startMonth();
        $from = Carbon::create($year, $m, 1)->startOfDay();

        return [$from, $from->copy()->addYear()->subDay()->endOfDay()];
    }

    /** The year that contains the date under the configured start month. */
    public function yearOf(?Carbon $date = null): int
    {
        $date ??= today();

        return $date->month >= $this->startMonth() ? $date->year : $date->year - 1;
    }

    /** @return array<string, float> hours per source inside [from, to] (all-time when both are null) */
    public function bySource(Employee $e, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $in = fn ($q, string $col) => $q->when($from, fn ($w) => $w->where($col, '>=', $from))->when($to, fn ($w) => $w->where($col, '<=', $to));
        $certs = $in(Certificate::with('program:id,owner_type')->where('employee_id', $e->id)->where('status', 'valid'), 'issued_at')->get();
        $external = $in(PdActivity::where('employee_id', $e->id)->where('status', 'approved'), 'starts_on')->get()->sum(fn ($a) => (float) ($a->approved_hours ?? $a->computed_hours));
        $kt = $in(KnowledgeTransfer::where('employee_id', $e->id)->where('status', 'approved'), 'delivered_on')->sum('hours');

        return [
            'center' => (float) $certs->filter(fn ($c) => $c->program?->owner_type !== 'school')->sum('hours'),
            'internal' => (float) $certs->filter(fn ($c) => $c->program?->owner_type === 'school')->sum('hours'),
            'external' => round((float) $external, 1),
            'knowledge_transfer' => round((float) $kt, 1),
        ];
    }

    public function allTime(Employee $e): float
    {
        return round(array_sum($this->bySource($e)), 1);
    }

    /** The target that applies to the employee: the one aimed at their job title or category, else the general one for the year. */
    public function targetFor(Employee $e, int $year): ?PdAnnualTarget
    {
        $e->loadMissing('jobTitle');
        $targets = PdAnnualTarget::where('year', $year)->get();

        return $targets->first(fn ($t) => in_array($e->job_title_id, $t->audience['job_title_ids'] ?? [], true) || ($e->jobTitle?->category && in_array($e->jobTitle->category, $t->audience['job_categories'] ?? [], true)))
            ?? $targets->first(fn ($t) => empty($t->audience['job_title_ids'] ?? []) && empty($t->audience['job_categories'] ?? []));
    }

    /** @return array<string, mixed> */
    public function summary(Employee $e, ?int $year = null): array
    {
        $year ??= $this->yearOf();
        [$from, $to] = $this->bounds($year);
        $raw = $this->bySource($e, $from, $to);
        $target = $this->targetFor($e, $year);
        $counts = $target?->counts ?? [];
        $counted = [];
        foreach (self::SOURCES as $s) {
            $on = $counts[$s] ?? true;
            $cap = $counts['caps'][$s] ?? null;
            $counted[$s] = $on ? ($cap !== null ? min($raw[$s], (float) $cap) : $raw[$s]) : 0.0;
        }
        $total = round(array_sum($counted), 1);
        $min = $target ? (float) $target->min_hours : null;

        return ['year' => $year, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'raw' => $raw, 'counted' => $counted, 'total' => $total, 'target' => $min, 'shortfall' => $min !== null ? max(0.0, round($min - $total, 1)) : null, 'percent' => $min ? min(100, round($total / $min * 100)) : null];
    }

    /** Quarterly (first day of a quarter) and in the last 60 days of the year: tell the employee and the manager who is short. */
    public function alertShortfalls(?Carbon $today = null): int
    {
        $today ??= today();
        $year = $this->yearOf($today);
        [, $to] = $this->bounds($year);
        $quarter = $today->day === 1 && in_array(($today->month - $this->startMonth() + 12) % 12, [0, 3, 6, 9], true);
        $yearEnd = $today->diffInDays($to, false) === 60;
        if (! $quarter && ! $yearEnd) {
            return 0;
        }
        $trigger = $year.':'.($yearEnd ? 'end' : 'q'.(intdiv(($today->month - $this->startMonth() + 12) % 12, 3) + 1));
        $n = 0;
        Employee::with('user', 'supervisor.user')->whereHas('user', fn ($q) => $q->where('status', 'active'))->chunkById(300, function ($employees) use ($year, $trigger, &$n) {
            foreach ($employees as $e) {
                $s = $this->summary($e, $year);
                if ($s['target'] === null || $s['shortfall'] <= 0 || ! $e->user_id || AppNotification::where('user_id', $e->user_id)->where('type', 'hours.shortfall')->where('data->trigger', $trigger)->exists()) {
                    continue;
                }
                $body = ['ar' => "أنجزت {$s['total']} من {$s['target']} ساعة تطوير مهني لهذا العام — تبقّى {$s['shortfall']}.", 'en' => "You have {$s['total']} of {$s['target']} professional-development hours this year — {$s['shortfall']} to go."];
                $this->notifications->send($e->user_id, 'hours.shortfall', ['ar' => 'ساعات التطوير المهني دون الحد', 'en' => 'Professional-development hours below the minimum'], $body, ['trigger' => $trigger]);
                if ($e->supervisor?->user_id) {
                    $this->notifications->send($e->supervisor->user_id, 'hours.shortfall', ['ar' => 'موظف دون الحد الأدنى من ساعات التطوير', 'en' => 'A team member is below the minimum PD hours'], ['ar' => ($e->user->name_ar ?: $e->user->name).': '.$body['ar'], 'en' => $e->user->name.': '.$body['en']], ['trigger' => $trigger]);
                }
                $n++;
            }
        });

        return $n;
    }
}
