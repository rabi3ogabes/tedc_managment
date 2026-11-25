<?php

namespace App\Services\Programs;

use App\Models\ProgramSession;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The programme list by day: which programmes train on a date, and the row of days to pick from.
 *
 * A programme «trains on a day» when one of its sessions that is not cancelled touches that day. Sessions are stored on the centre's own
 * clock (APP_TIMEZONE), so a day is read with local bounds.
 *
 * The row of days leaves out the days off (the weekend, a weekly holiday, a vacation: CalendarService) because nobody looks for training
 * on them. A day off that does have training (it was approved) stays in the row, and so does a working day without any programme: that
 * one is shown with its light off.
 */
class ProgramDays
{
    /** How far from the chosen day the row looks for days to show (a long vacation is skipped in one go). */
    public const SCAN_DAYS = 75;

    public const MAX_BEFORE = 14;

    public const MAX_AFTER = 21;

    public function __construct(private readonly CalendarService $calendar) {}

    /** Sessions that are not cancelled and touch a day between the two dates (both included). */
    public static function touching(Builder $sessions, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $sessions->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', CarbonImmutable::parse($to->toDateString())->endOfDay())
            ->where('ends_at', '>=', CarbonImmutable::parse($from->toDateString())->startOfDay());
    }

    /** Narrows a programme query to the programmes that train on the day. */
    public function onDay(Builder $programs, CarbonInterface $day): Builder
    {
        return $programs->whereHas('sessions', fn (Builder $s) => self::touching($s, $day, $day));
    }

    /** Orders a programme query by the hour its first session of the day starts: the list reads like the day's agenda. */
    public function byFirstSession(Builder $programs, CarbonInterface $day): Builder
    {
        $first = self::touching(ProgramSession::query(), $day, $day)->selectRaw('min(starts_at)')->whereColumn('program_sessions.program_id', 'programs.id');

        return $programs->orderBy($first->toBase())->orderBy('programs.code');
    }

    /**
     * The sessions of the given programmes on the day, by programme, as the list shows them (hours, room, trainer).
     *
     * @param  iterable<int, string>  $programIds
     * @return Collection<string, list<array<string, mixed>>>
     */
    public function sessionsOn(iterable $programIds, CarbonInterface $day): Collection
    {
        return self::touching(ProgramSession::query(), $day, $day)
            ->whereIn('program_id', collect($programIds)->all())
            ->with(['room:id,code,name_ar,name_en', 'trainer:id,name_ar,name_en'])
            ->orderBy('starts_at')
            ->get()
            ->groupBy('program_id')
            ->map(fn (Collection $rows) => $rows->map(fn (ProgramSession $s) => [
                'id' => $s->id,
                'title' => $s->translate('title'),
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
                'mode' => $s->mode,
                'room' => $s->room?->translate('name'),
                'location' => $s->location_text,
                'trainer' => $s->trainer?->translate('name'),
            ])->values()->all());
    }

    /**
     * The row of days around a date: `before` days ahead of it and `after` days behind it, days off left out.
     *
     * The chosen day itself is always in the row, even when it is a day off, so a day picked from the calendar can be shown as chosen.
     * `prev` and `next` are the first days beyond the two ends of the row (null when the scan found none).
     *
     * @param  Builder  $programs  the programmes the reader may see, already narrowed by the filters of the list
     * @return array{around: string, today: string, days: list<array<string, mixed>>, prev: ?string, next: ?string}
     */
    public function window(Builder $programs, CarbonInterface $around, int $before, int $after): array
    {
        $around = CarbonImmutable::parse($around->toDateString());
        $before = max(0, min(self::MAX_BEFORE, $before));
        $after = max(0, min(self::MAX_AFTER, $after));
        $from = $around->subDays(self::SCAN_DAYS);
        $to = $around->addDays(self::SCAN_DAYS);

        // One read of the sessions of the whole scan, then plain counting: a session is counted on every day it touches.
        $programsOn = [];
        $sessionsOn = [];
        self::touching(ProgramSession::query(), $from, $to)
            ->whereIn('program_id', $programs->select('programs.id'))
            ->get(['id', 'program_id', 'starts_at', 'ends_at'])
            ->each(function (ProgramSession $s) use (&$programsOn, &$sessionsOn, $from, $to) {
                $day = CarbonImmutable::parse($s->starts_at->toDateString())->max($from);
                $last = CarbonImmutable::parse($s->ends_at->toDateString())->min($to);
                for (; $day->lte($last); $day = $day->addDay()) {
                    $programsOn[$day->toDateString()][$s->program_id] = true;
                    $sessionsOn[$day->toDateString()] = ($sessionsOn[$day->toDateString()] ?? 0) + 1;
                }
            });

        $calendar = collect($this->calendar->range($from, $to, false)['days'])->keyBy('date');
        $describe = function (CarbonImmutable $date) use ($calendar, $programsOn, $sessionsOn): array {
            $key = $date->toDateString();
            $c = $calendar->get($key);

            return [
                'date' => $key,
                'weekday' => $date->dayOfWeek,
                'programs' => count($programsOn[$key] ?? []),
                'sessions' => $sessionsOn[$key] ?? 0,
                'off' => ! $c['is_working_day'],
                'kind' => $c['kind'],
                'title' => $c['entry']['title'] ?? $c['weekly']['title'] ?? null,
            ];
        };
        $shown = fn (array $day): bool => ! $day['off'] || $day['programs'] > 0;

        // Walk away from the chosen day in one direction, keeping the days to show; one more than asked gives the day beyond the end.
        $walk = function (int $step, int $wanted) use ($around, $from, $to, $describe, $shown): array {
            $found = [];
            for ($date = $around->addDays($step); $date->betweenIncluded($from, $to) && count($found) <= $wanted; $date = $date->addDays($step)) {
                $day = $describe($date);
                if ($shown($day)) {
                    $found[] = $day;
                }
            }

            return [array_slice($found, 0, $wanted), $found[$wanted]['date'] ?? null];
        };
        [$earlier, $prev] = $walk(-1, $before);
        [$later, $next] = $walk(1, $after);

        return [
            'around' => $around->toDateString(),
            'today' => CarbonImmutable::now()->toDateString(),
            'days' => [...array_reverse($earlier), $describe($around), ...$later],
            'prev' => $prev,
            'next' => $next,
        ];
    }
}
