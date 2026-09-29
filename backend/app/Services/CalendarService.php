<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CalendarApproval;
use App\Models\CalendarDay;
use App\Models\ProgramSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Working / off day calendar of the training center.
 *
 * Every date resolves to one "kind":
 *   workday  - unmarked weekday, open for training
 *   weekend  - configured weekend (Friday and Saturday), off, closed for training
 *   vacation - marked vacation, off, closed for training
 *   exam     - marked exam day, working day but closed for training
 *   normal   - marked normal day, working day but closed for training
 *
 * A closed day becomes open for training only through an explicit approval.
 */
class CalendarService
{
    public const MAX_RANGE_DAYS = 800;

    /**
     * @return array{days: list<array>, summary: array}
     */
    public function range(CarbonInterface $from, CarbonInterface $to, bool $withSessions = true): array
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());

        $entries = CalendarDay::whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()->keyBy(fn ($d) => $d->date->toDateString());
        $approvals = CalendarApproval::with('approver:id,name,name_ar')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()->keyBy(fn ($a) => $a->date->toDateString());
        $sessions = $withSessions ? $this->sessionCounts($from, $to) : [];

        $days = [];
        $summary = ['total' => 0, 'working' => 0, 'off' => 0, 'vacation' => 0, 'exam' => 0, 'normal' => 0, 'weekend' => 0, 'approved' => 0, 'open_for_training' => 0, 'sessions' => 0];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $day = $this->describe($date, $entries->get($key), $approvals->get($key));
            $day['sessions_count'] = $sessions[$key] ?? 0;
            $days[] = $day;

            $summary['total']++;
            $summary[$day['is_working_day'] ? 'working' : 'off']++;
            $summary[$day['kind']] = ($summary[$day['kind']] ?? 0) + 1;
            $summary['approved'] += $day['approval'] ? 1 : 0;
            $summary['open_for_training'] += $day['training_allowed'] ? 1 : 0;
            $summary['sessions'] += $day['sessions_count'];
        }

        return ['days' => $days, 'summary' => $summary];
    }

    public function describe(CarbonInterface $date, ?CalendarDay $entry = null, ?CalendarApproval $approval = null): array
    {
        $kind = $entry?->type ?? ($this->isWeekend($date) ? 'weekend' : 'workday');
        $isWorking = in_array($kind, ['workday', 'exam', 'normal'], true);
        $closed = $kind !== 'workday';

        return [
            'date' => $date->toDateString(),
            'weekday' => $date->dayOfWeek,
            'kind' => $kind,
            'is_working_day' => $isWorking,
            'is_weekend' => $this->isWeekend($date),
            'training_allowed' => ! $closed || $approval !== null,
            'requires_approval' => $closed && $approval === null,
            'entry' => $entry ? [
                'id' => $entry->id, 'type' => $entry->type, 'title' => $entry->translate('title'),
                'title_ar' => $entry->title_ar, 'title_en' => $entry->title_en, 'notes' => $entry->notes,
            ] : null,
            'approval' => $approval ? [
                'id' => $approval->id, 'reason' => $approval->reason, 'approved_at' => $approval->created_at?->toIso8601String(),
                'approved_by' => $approval->relationLoaded('approver') ? $approval->approver?->name : null,
            ] : null,
        ];
    }

    public function isWeekend(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeek, config('tedc.calendar.weekend', [5, 6]), true);
    }

    /**
     * Dates within [start, end] that are closed for training and not approved.
     *
     * @return Collection<int, array>
     */
    public function closedDates(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return collect($this->range($start, $end, false)['days'])->where('training_allowed', false)->values();
    }

    /**
     * @throws BusinessRuleException when the span touches a closed, unapproved day
     */
    public function assertTrainingAllowed(CarbonInterface $start, CarbonInterface $end): void
    {
        $closed = $this->closedDates($start, $end);
        if ($closed->isEmpty()) {
            return;
        }

        throw new BusinessRuleException(
            __('messages.calendar.closed_for_training', ['dates' => $closed->pluck('date')->implode(', ')]),
            'calendar_closed',
            ['dates' => $closed->map(fn ($d) => ['date' => $d['date'], 'kind' => $d['kind']])->all()],
        );
    }

    /** Approve every closed, unapproved day of the span with the same reason. */
    public function approveSpan(CarbonInterface $start, CarbonInterface $end, string $reason, ?string $userId): int
    {
        $count = 0;
        foreach ($this->closedDates($start, $end) as $day) {
            CalendarApproval::create(['date' => $day['date'], 'reason' => $reason, 'approved_by' => $userId]);
            $count++;
        }

        return $count;
    }

    /**
     * Sessions that already sit on the given dates (used to warn when a day is closed afterwards).
     */
    public function sessionsOn(CarbonInterface $from, CarbonInterface $to)
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());

        return ProgramSession::with('program:id,code,title_ar,title_en')
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', $to->endOfDay())
            ->where('ends_at', '>=', $from->startOfDay())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Sessions that sit on a day that is currently closed for training and not approved.
     *
     * @return Collection<int, ProgramSession>
     */
    public function conflictingSessions(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $closed = $this->closedDates($from, $to)->pluck('date')->flip();
        if ($closed->isEmpty()) {
            return collect();
        }

        return $this->sessionsOn($from, $to)->filter(function (ProgramSession $session) use ($closed) {
            $day = CarbonImmutable::parse($session->starts_at->toDateString());
            for ($last = CarbonImmutable::parse($session->ends_at->toDateString()); $day->lte($last); $day = $day->addDay()) {
                if ($closed->has($day->toDateString())) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /** @return array<string, int> date => number of sessions touching that date */
    private function sessionCounts(CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = [];
        foreach ($this->sessionsOn($from, $to) as $session) {
            $day = CarbonImmutable::parse($session->starts_at->toDateString());
            $last = CarbonImmutable::parse($session->ends_at->toDateString());
            for (; $day->lte($last); $day = $day->addDay()) {
                $counts[$day->toDateString()] = ($counts[$day->toDateString()] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
