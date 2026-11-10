<?php

namespace App\Services\Programs;

use App\Exceptions\BusinessRuleException;
use App\Models\RoomBooking;
use App\Models\TrainingRoom;
use App\Services\CalendarService;
use App\Services\RoomService;
use App\Services\TrainerService;
use App\Services\TrainingDaySettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The session plan of a new programme's first group, generated from what the administrator filled in: the start date,
 * the hours, the capacity and how the sessions recur — consecutive training days, one fixed weekday every N weeks
 * ("every Monday for five weeks", "every other Sunday"), or chosen dates. Each session gets the program day's hours,
 * closed days are skipped (and said so), and a room is fixed for the whole series when one is free on every date, the
 * preferred one first; when none is, each session takes the best free room and the plan says which ones differ. The
 * plan is advice: every session is annotated (closed day, outside the day window, room or trainer busy) and the
 * same annotation re-checks a plan the person edited. Nothing is written here.
 */
class ProgramSchedulePlanner
{
    public const PATTERNS = ['consecutive', 'weekly', 'custom'];

    public const ROOM_MODES = ['fixed', 'per_session', 'none'];

    public const MAX_SESSIONS = 60;

    public function __construct(private readonly CalendarService $calendar, private readonly RoomService $rooms, private readonly TrainerService $trainers, private readonly TrainingDaySettings $day) {}

    // ───────────────────────────── generate

    /** @param  array<string, mixed>  $in */
    public function plan(array $in): array
    {
        $notes = [];
        $total = max(0.25, (float) ($in['total_hours'] ?? 12));
        $dayHours = $this->day->hours();
        $startTime = $in['start_time'] ?? $this->day->all()['day_start'];
        $pattern = in_array($in['pattern'] ?? null, self::PATTERNS, true) ? $in['pattern'] : 'weekly';
        $start = CarbonImmutable::parse($in['start_date'] ?? today()->addDays(14)->toDateString())->startOfDay();
        $every = max(1, min(8, (int) ($in['every_weeks'] ?? 1)));
        $weekday = isset($in['weekday']) ? (int) $in['weekday'] : $start->dayOfWeek;

        $custom = collect($in['dates'] ?? [])->map(fn ($d) => CarbonImmutable::parse($d)->startOfDay())->unique(fn ($d) => $d->toDateString())->sortBy(fn ($d) => $d->timestamp)->values();
        if ($pattern === 'custom' && $custom->isNotEmpty()) {
            $count = min(self::MAX_SESSIONS, $custom->count());
            $dates = $custom->take($count)->all();
        } else {
            $count = max(1, min(self::MAX_SESSIONS, (int) ($in['sessions_count'] ?? ceil($total / $dayHours))));
            $dates = $this->dates($pattern === 'custom' ? 'consecutive' : $pattern, $start, $count, $weekday, $every, $notes);
            $count = count($dates);
        }
        if ($dates === []) {
            $notes = [];
            $notes[] = $this->note('no_open_days', 'warn', 'لا توجد أيام تدريب مفتوحة بهذا النمط؛ جرّب يوماً آخر أو تواريخ مخصصة.', 'No open training days fit this pattern; try another weekday or chosen dates.');

            return $this->result([], $in, $notes, [], $pattern, $weekday, $every);
        }
        [$hours, $count, $raised] = $this->split($total, $count, $dayHours);
        if ($raised) {
            $notes[] = $this->note('count_raised', 'info', 'زِيد عدد الجلسات ليتسع لساعات البرنامج ضمن اليوم التدريبي.', 'The number of sessions was raised so the hours fit inside the training day.');
            $dates = $pattern === 'custom' && $custom->isNotEmpty() ? $custom->take($count)->all() : $this->dates($pattern === 'custom' ? 'consecutive' : $pattern, $start, $count, $weekday, $every, $notes);
            [$hours, $count] = $this->split($total, count($dates), $dayHours);
        }

        $sessions = [];
        foreach (array_slice($dates, 0, $count) as $i => $date) {
            $from = CarbonImmutable::parse($date->toDateString().' '.$startTime);
            $to = $from->addMinutes((int) round($hours[$i] * 60));
            $sessions[] = $this->row($i + 1, $from, $to, null, $in['trainer_id'] ?? null, $hours[$i], ! empty($in['_online']));
        }

        return $this->assemble($sessions, $in, $notes, $pattern, $weekday, $every, true);
    }

    /** Re-checks a plan the person edited: the same annotation, rooms untouched. */
    public function check(array $in): array
    {
        $sessions = [];
        foreach (array_values($in['sessions'] ?? []) as $i => $s) {
            $from = CarbonImmutable::parse($s['starts_at']);
            $to = CarbonImmutable::parse($s['ends_at']);
            $sessions[] = $this->row($i + 1, $from, $to, $s['training_room_id'] ?? null, $s['trainer_id'] ?? ($in['trainer_id'] ?? null), round($from->diffInMinutes($to) / 60, 2), ($s['mode'] ?? null) === 'online', $s['title_ar'] ?? null, $s['title_en'] ?? null);
        }
        usort($sessions, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));
        foreach ($sessions as $i => &$row) {
            $row['sequence'] = $i + 1;
        }
        unset($row);

        return $this->assemble($sessions, $in, [], $in['pattern'] ?? 'custom', (int) ($in['weekday'] ?? 1), (int) ($in['every_weeks'] ?? 1), false);
    }

    // ───────────────────────────── dates

    /** @return list<CarbonImmutable> */
    private function dates(string $pattern, CarbonImmutable $start, int $count, int $weekday, int $every, array &$notes): array
    {
        $horizon = $start->addDays(max(120, $count * 7 * $every + 90));
        $days = collect($this->calendar->range($start, $horizon, false)['days'])->keyBy('date');
        $open = fn (CarbonImmutable $d) => (bool) ($days[$d->toDateString()]['training_allowed'] ?? true);
        $out = [];

        if ($pattern === 'consecutive') {
            for ($d = $start; count($out) < $count && $d->lte($horizon); $d = $d->addDay()) {
                if ($open($d)) {
                    $out[] = $d;
                }
            }

            return $out;
        }

        $d = $start;
        for ($guard = 0; $d->dayOfWeek !== $weekday && $guard < 7; $guard++) {
            $d = $d->addDay();
        }
        while (count($out) < $count && $d->lte($horizon)) {
            if ($open($d)) {
                $out[] = $d;
            } else {
                $title = $days[$d->toDateString()]['entry']['title'] ?? null;
                $notes[] = $this->note('skipped_closed', 'info', 'تُخطّي '.$d->toDateString().' لأنه يوم غير متاح للتدريب'.($title ? " ({$title})" : '').'؛ انتقلت الجلسة إلى الموعد التالي في السلسلة.', 'Skipped '.$d->toDateString().' because it is closed for training'.($title ? " ({$title})" : '').'; the session moved to the next date in the series.', ['date' => $d->toDateString()]);
            }
            $d = $d->addWeeks($every);
        }

        return $out;
    }

    /** @return array{0: list<float>, 1: int, 2: bool} hours per session (quarter hours, the last takes the rest), the count, whether the count had to be raised */
    private function split(float $total, int $count, float $dayHours): array
    {
        $raised = false;
        if ($total / $count > $dayHours + 1e-9) {
            $count = min(self::MAX_SESSIONS, (int) ceil($total / $dayHours));
            $raised = true;
        }
        do {
            $each = ceil($total / $count * 4) / 4;
            $last = round($total - $each * ($count - 1), 2);
        } while ($last <= 0 && $count > 1 && $count--);
        $hours = array_fill(0, $count, $each);
        $hours[$count - 1] = $count === 1 ? $total : $last;

        return [$hours, $count, $raised];
    }

    // ───────────────────────────── rooms, flags, summary

    private function assemble(array $sessions, array $in, array $notes, string $pattern, int $weekday, int $every, bool $assign): array
    {
        $online = ($in['delivery_mode'] ?? 'in_person') === 'online';
        $capacity = (int) ($in['capacity'] ?? 0);
        $roomMode = in_array($in['room_mode'] ?? null, self::ROOM_MODES, true) ? $in['room_mode'] : 'fixed';
        $rooms = [];
        $summaryRoom = ['mode' => $online ? 'remote' : 'none', 'room_id' => null, 'name_ar' => null, 'name_en' => null];

        if ($online) {
            foreach ($sessions as &$s) {
                $s['mode'] = 'online';
                $s['training_room_id'] = null;
                $s['room'] = null;
            }
            unset($s);
        } elseif ($sessions !== []) {
            $matrix = $this->availability($sessions, $capacity);
            if ($assign && $roomMode !== 'none') {
                [$sessions, $summaryRoom, $roomNotes] = $this->assignRooms($sessions, $matrix, $roomMode, $in['room_id'] ?? null);
                $notes = array_merge($notes, $roomNotes);
            } else {
                $summaryRoom = $this->describeRooms($sessions, $matrix);
            }
            $rooms = $this->roomList($sessions, $matrix);
        }

        $sessions = $this->flag($sessions, $matrix ?? [], $online, $assign);

        return $this->result($sessions, $in, $notes, $rooms, $pattern, $weekday, $every, $summaryRoom);
    }

    /** @return array{free: array<string, array<int, bool>>, rooms: Collection<string, TrainingRoom>, fits: array<string, bool>, score: array<string, int>} */
    private function availability(array $sessions, int $capacity): array
    {
        $min = CarbonImmutable::parse(min(array_column($sessions, 'starts_at')));
        $max = CarbonImmutable::parse(max(array_column($sessions, 'ends_at')));
        $bookings = RoomBooking::where('status', 'confirmed')->where('starts_at', '<', $max)->where('ends_at', '>', $min)->get()->groupBy('room_id');
        $free = [];
        $fits = [];
        $score = [];
        $rooms = collect();
        foreach ($sessions as $i => $s) {
            $from = CarbonImmutable::parse($s['starts_at']);
            $to = CarbonImmutable::parse($s['ends_at']);
            foreach ($this->rooms->suggest($from, $to, ['capacity' => $capacity]) as $r) {
                $id = $r['room']->id;
                $held = ($bookings[$id] ?? collect())->contains(fn ($b) => $b->starts_at < $to && $b->ends_at > $from);
                $free[$id][$i] = $r['available'] && ! $held;
                $fits[$id] = $r['fits_capacity'];
                $score[$id] = max($score[$id] ?? 0, (int) $r['score']);
                $rooms[$id] = $r['room'];
            }
        }

        return compact('free', 'rooms', 'fits', 'score');
    }

    /** @return array{0: list<array>, 1: array, 2: list<array>} */
    private function assignRooms(array $sessions, array $m, string $mode, ?string $preferred): array
    {
        $notes = [];
        $n = count($sessions);
        $freeAll = fn (string $id) => count(array_filter($m['free'][$id] ?? [])) === $n && ($m['fits'][$id] ?? false);
        $ranked = $m['rooms']->keys()->sortBy([fn ($a, $b) => ($m['score'][$b] ?? 0) <=> ($m['score'][$a] ?? 0), fn ($a, $b) => strcmp($m['rooms'][$a]->name_ar, $m['rooms'][$b]->name_ar)])->values();
        $fixed = null;

        if ($mode === 'fixed') {
            if ($preferred && $freeAll($preferred)) {
                $fixed = $preferred;
            } else {
                $fixed = $ranked->first($freeAll);
                if ($preferred && isset($m['rooms'][$preferred])) {
                    $name = $m['rooms'][$preferred];
                    $busy = collect($sessions)->filter(fn ($s, $i) => ! ($m['free'][$preferred][$i] ?? false))->pluck('date')->all();
                    $why = $m['fits'][$preferred] ?? true ? ['ar' => 'مشغولة في '.implode('، ', $busy), 'en' => 'busy on '.implode(', ', $busy)] : ['ar' => 'لا تتسع للمتدربين', 'en' => 'too small for the trainees'];
                    $notes[] = $this->note('preferred_room_unavailable', 'warn', "القاعة «{$name->name_ar}» ليست متاحة في كل المواعيد ({$why['ar']})".($fixed ? '؛ اختيرت قاعة ثابتة بديلة.' : '؛ اختيرت أفضل قاعة لكل جلسة.'), "Room \"{$name->name_en}\" is not free on every date ({$why['en']})".($fixed ? '; another room was fixed for the whole series.' : '; the best free room was picked for each session.'), ['room_id' => $preferred]);
                }
            }
        }

        $used = [];
        foreach ($sessions as $i => &$s) {
            $id = $fixed ?? ($mode === 'none' ? null : $ranked->first(fn ($rid) => ($m['free'][$rid][$i] ?? false) && ($m['fits'][$rid] ?? false)));
            $s['training_room_id'] = $id;
            $s['room'] = $id ? $this->roomRow($m['rooms'][$id]) : null;
            if ($id) {
                $used[$id] = true;
            }
        }
        unset($s);

        $summary = $fixed
            ? ['mode' => 'fixed', 'room_id' => $fixed] + $this->roomNames($m['rooms'][$fixed])
            : (count($used) > 0 ? ['mode' => 'mixed', 'room_id' => null, 'name_ar' => null, 'name_en' => null] : ['mode' => 'none', 'room_id' => null, 'name_ar' => null, 'name_en' => null]);
        if ($mode === 'fixed' && ! $fixed && count($used) > 0) {
            $notes[] = $this->note('no_single_room', 'warn', 'لا توجد قاعة متاحة في كل المواعيد؛ اختيرت أفضل قاعة متاحة لكل جلسة وتختلف القاعة بين الجلسات.', 'No single room is free on every date; the best free room was picked for each session, so the room changes between sessions.');
        }

        return [$sessions, $summary, $notes];
    }

    private function describeRooms(array $sessions, array $m): array
    {
        $ids = collect($sessions)->pluck('training_room_id')->filter()->unique()->values();
        if ($ids->count() === 1 && count(array_filter(array_column($sessions, 'training_room_id'))) === count($sessions)) {
            return ['mode' => 'fixed', 'room_id' => $ids[0]] + $this->roomNames($m['rooms'][$ids[0]] ?? null);
        }

        return $ids->isEmpty() ? ['mode' => 'none', 'room_id' => null, 'name_ar' => null, 'name_en' => null] : ['mode' => 'mixed', 'room_id' => null, 'name_ar' => null, 'name_en' => null];
    }

    /** Every active room with how many of the plan's dates it is free on, the best first. @return list<array> */
    private function roomList(array $sessions, array $m): array
    {
        $n = count($sessions);

        return $m['rooms']->keys()->map(fn ($id) => ['id' => $id] + $this->roomNames($m['rooms'][$id]) + ['capacity' => $m['rooms'][$id]->capacity, 'fits' => (bool) ($m['fits'][$id] ?? false), 'free_count' => count(array_filter($m['free'][$id] ?? [])), 'dates' => $n, 'free_all' => count(array_filter($m['free'][$id] ?? [])) === $n, 'score' => $m['score'][$id] ?? 0])
            ->sortBy([['free_all', 'desc'], ['fits', 'desc'], ['free_count', 'desc'], ['score', 'desc'], ['name_ar', 'asc']])->values()->all();
    }

    private function flag(array $sessions, array $m, bool $online, bool $assign): array
    {
        $days = $sessions === [] ? collect() : collect($this->calendar->range(CarbonImmutable::parse(min(array_column($sessions, 'starts_at'))), CarbonImmutable::parse(max(array_column($sessions, 'starts_at'))), false)['days'])->keyBy('date');
        $series = collect($sessions)->pluck('training_room_id')->filter()->countBy()->sortDesc()->keys()->first();
        foreach ($sessions as $i => &$s) {
            $from = CarbonImmutable::parse($s['starts_at']);
            $to = CarbonImmutable::parse($s['ends_at']);
            $flags = [];
            $day = $days[$s['date']] ?? null;
            if ($day && ! $day['training_allowed']) {
                $label = $day['entry']['title'] ?? ($day['is_weekend'] ? null : null);
                $flags[] = $this->note('closed_day', 'error', 'يوم غير متاح للتدريب'.($label ? " ({$label})" : ($day['is_weekend'] ? ' (عطلة نهاية الأسبوع)' : '')).'؛ يحتاج اعتماد الإدارة.', 'Closed for training'.($label ? " ({$label})" : ($day['is_weekend'] ? ' (weekend)' : '')).'; it needs the management\'s approval.');
            }
            try {
                $this->day->assertWithinDay($from, $to);
            } catch (BusinessRuleException $e) {
                $flags[] = $this->note('outside_day', 'error', $e->getMessage(), $e->getMessage());
            }
            if ($from->isPast()) {
                $flags[] = $this->note('past', 'warn', 'موعد الجلسة في الماضي.', 'The session date is in the past.');
            }
            if (! $online) {
                $room = $s['training_room_id'] ?? null;
                if (! $room) {
                    $flags[] = $this->note('no_room', 'warn', 'لا قاعة لهذه الجلسة.', 'No room for this session.');
                } else {
                    if (isset($m['free']) && array_key_exists($room, $m['free']) && ! ($m['free'][$room][$i] ?? true)) {
                        $flags[] = $this->note('room_busy', 'error', 'القاعة محجوزة في هذا الموعد.', 'The room is booked at this time.');
                    }
                    if ($assign && $series && $room !== $series) {
                        $flags[] = $this->note('room_differs', 'info', 'قاعة مختلفة عن بقية الجلسات.', 'A different room from the other sessions.');
                    }
                }
            }
            if (! empty($s['trainer_id']) && $this->trainers->conflicts($s['trainer_id'], $from, $to)->isNotEmpty()) {
                $flags[] = $this->note('trainer_busy', 'error', 'المدرب لديه جلسة أخرى في هذا الوقت.', 'The trainer has another session at this time.');
            }
            $s['flags'] = $flags;
            $s['status'] = collect($flags)->contains('level', 'error') ? 'error' : (collect($flags)->contains('level', 'warn') ? 'warn' : 'ok');
        }
        unset($s);

        return $sessions;
    }

    // ───────────────────────────── rows and result

    private function row(int $n, CarbonImmutable $from, CarbonImmutable $to, ?string $roomId, ?string $trainerId, float $hours, bool $online, ?string $titleAr = null, ?string $titleEn = null): array
    {
        return [
            'sequence' => $n, 'title_ar' => $titleAr ?: 'الجلسة '.$n, 'title_en' => $titleEn ?: 'Session '.$n,
            'date' => $from->toDateString(), 'weekday' => $from->dayOfWeek, 'starts_at' => $from->format('Y-m-d H:i:s'), 'ends_at' => $to->format('Y-m-d H:i:s'),
            'start_time' => $from->format('H:i'), 'end_time' => $to->format('H:i'), 'hours' => $hours, 'mode' => $online ? 'online' : 'in_person',
            'training_room_id' => $roomId, 'room' => null, 'trainer_id' => $trainerId, 'flags' => [], 'status' => 'ok',
        ];
    }

    private function result(array $sessions, array $in, array $notes, array $rooms, string $pattern, int $weekday, int $every, ?array $room = null): array
    {
        $sessions = array_map(function ($s) use ($rooms) {
            if (! $s['room'] && $s['training_room_id']) {
                $r = collect($rooms)->firstWhere('id', $s['training_room_id']);
                $s['room'] = $r ? ['id' => $r['id'], 'name_ar' => $r['name_ar'], 'name_en' => $r['name_en']] : null;
            }

            return $s;
        }, $sessions);
        $errors = collect($sessions)->where('status', 'error')->count();

        return [
            'sessions' => $sessions, 'rooms' => $rooms, 'notes' => $notes,
            'summary' => [
                'count' => count($sessions), 'hours' => round(array_sum(array_column($sessions, 'hours')), 2), 'first' => $sessions[0]['date'] ?? null, 'last' => $sessions ? end($sessions)['date'] : null,
                'pattern' => $pattern, 'weekday' => $weekday, 'every_weeks' => $every, 'room' => $room ?? ['mode' => 'none', 'room_id' => null, 'name_ar' => null, 'name_en' => null],
                'errors' => $errors, 'warnings' => collect($sessions)->where('status', 'warn')->count(), 'day_hours' => $this->day->hours(), 'day_start' => $this->day->all()['day_start'], 'day_end' => $this->day->all()['day_end'],
                'weekend' => array_values(config('tedc.calendar.weekend', [5, 6])),
            ],
        ];
    }

    private function roomRow(TrainingRoom $r): array
    {
        return ['id' => $r->id] + $this->roomNames($r);
    }

    /** @return array{name_ar: ?string, name_en: ?string} */
    private function roomNames(?TrainingRoom $r): array
    {
        return ['name_ar' => $r?->name_ar, 'name_en' => $r?->name_en ?: $r?->name_ar];
    }

    private function note(string $code, string $level, string $ar, string $en, array $extra = []): array
    {
        return ['code' => $code, 'level' => $level, 'ar' => $ar, 'en' => $en] + $extra;
    }
}
