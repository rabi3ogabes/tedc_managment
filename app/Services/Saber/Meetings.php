<?php

namespace App\Services\Saber;

use App\Exceptions\BusinessRuleException;
use App\Models\CalendarApproval;
use App\Models\CalendarDay;
use App\Models\ProgramSession;
use App\Models\RoomBooking;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\RoomService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * سابر+ rooms and meetings (docs/saber/03-rooms-and-meetings.md, C2–C4): meeting-room bookings by the hour (07:00–14:00), once or repeated
 * daily, weekly or every two weeks; a holiday refuses them unless it allows events, and every hour must be free in the room. Also the month
 * of every room (sessions and bookings day by day) that the rooms calendar and the room map read.
 */
class Meetings
{
    /** The bookable hours of a day: slots start at 07:00 and the last one ends at 14:00. */
    public const FIRST_HOUR = 7;

    public const LAST_HOUR = 14;

    public const STEPS = ['daily' => 1, 'weekly' => 7, 'biweekly' => 14];

    public function __construct(private readonly CalendarService $calendar, private readonly RoomService $rooms) {}

    /**
     * @param  array{room_id: string, title: string, requested_by: string, reason: string, purpose: string, date: string, from_hour: int, to_hour: int, recurrence?: ?string, count?: ?int, attendees?: ?int}  $d
     * @return Collection<int, RoomBooking>
     */
    public function book(array $d, User $actor): Collection
    {
        $dates = $this->dates($d['date'], $d['recurrence'] ?? null, (int) ($d['count'] ?? 1));
        $this->assertOpen($dates);
        $this->assertFree($d['room_id'], $dates, (int) $d['from_hour'], (int) $d['to_hour']);
        $series = count($dates) > 1 ? (string) Str::uuid() : null;

        return DB::transaction(fn () => collect($dates)->map(fn (string $date) => RoomBooking::create([
            'room_id' => $d['room_id'], 'purpose' => $d['purpose'], 'title' => $d['title'], 'requested_by' => $d['requested_by'], 'reason' => $d['reason'],
            'starts_at' => $this->at($date, (int) $d['from_hour']), 'ends_at' => $this->at($date, (int) $d['to_hour']), 'attendees' => $d['attendees'] ?? null,
            'booked_by' => $actor->id, 'status' => 'confirmed', 'series_id' => $series, 'recurrence' => $series ? $d['recurrence'] : null,
        ])));
    }

    /**
     * Title, who asked and why change for the whole series when asked; the hours change for this booking only, checked again.
     *
     * @param  array{title?: string, requested_by?: string, reason?: string, from_hour?: int, to_hour?: int, series?: bool}  $d
     */
    public function update(RoomBooking $booking, array $d): RoomBooking
    {
        DB::transaction(function () use ($booking, $d) {
            if (isset($d['from_hour']) || isset($d['to_hour'])) {
                $date = $booking->starts_at->timezone(config('app.timezone'))->toDateString();
                $from = (int) ($d['from_hour'] ?? $booking->starts_at->timezone(config('app.timezone'))->hour);
                $to = (int) ($d['to_hour'] ?? $booking->ends_at->timezone(config('app.timezone'))->hour);
                $this->assertHours($from, $to);
                $this->assertFree($booking->room_id, [$date], $from, $to, $booking->id);
                $booking->update(['starts_at' => $this->at($date, $from), 'ends_at' => $this->at($date, $to)]);
            }
            $text = array_intersect_key($d, array_flip(['title', 'requested_by', 'reason']));
            if ($text !== []) {
                $scope = ! empty($d['series']) && $booking->series_id ? RoomBooking::where('series_id', $booking->series_id) : RoomBooking::whereKey($booking->id);
                $scope->where('status', 'confirmed')->get()->each->update($text);
            }
        });

        return $booking->fresh();
    }

    /** @return int bookings cancelled */
    public function cancel(RoomBooking $booking, bool $series): int
    {
        $rows = $series && $booking->series_id
            ? RoomBooking::where('series_id', $booking->series_id)->where('status', 'confirmed')->where('starts_at', '>=', now()->startOfDay())->get()
            : collect([$booking]);
        $rows->each->update(['status' => 'cancelled']);

        return $rows->count();
    }

    /** The booked hours of a room on a day, for the slot picker: each slot free or held (and by what). */
    public function slots(string $roomId, string $date, ?string $exceptBookingId = null): array
    {
        $tz = config('app.timezone');
        $out = [];
        for ($h = self::FIRST_HOUR; $h < self::LAST_HOUR; $h++) {
            $holders = $this->rooms->occupants($roomId, CarbonImmutable::parse("{$date} {$h}:00", $tz), CarbonImmutable::parse("{$date} {$h}:00", $tz)->addHour(), null, $exceptBookingId);
            $out[] = ['hour' => $h, 'busy' => $holders !== [], 'by' => $holders[0]['program'] ?? $holders[0]['title'] ?? null];
        }
        $day = $this->day($date);

        return ['slots' => $out, 'day' => ['events_allowed' => $this->eventsAllowed($day), 'kind' => $day['kind'], 'label' => $day['entry']['title'] ?? $day['weekly']['title'] ?? null]];
    }

    /**
     * Every active room and, day by day for the month, the calendar state and what holds each room: training days (programme, group, trainer)
     * and bookings (title, who asked, why, hours, series).
     *
     * @return array<string, mixed>
     */
    public function month(string $ym): array
    {
        $tz = config('app.timezone');
        $first = CarbonImmutable::parse("{$ym}-01", $tz)->startOfDay();
        $last = $first->endOfMonth();
        $rooms = TrainingRoom::where('status', '!=', 'inactive')->orderByRaw("case when room_type = 'meeting' then 1 else 0 end")->orderBy('tag')->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'room_type', 'tag', 'capacity', 'building', 'floor', 'layout']);
        $sessions = ProgramSession::with(['program:id,code,title_ar,title_en', 'trainingGroup:id,sequence,title_ar,color', 'trainer:id,name_ar,name_en'])
            ->whereNotNull('training_room_id')->where('status', '!=', 'cancelled')->where('starts_at', '<', $last->utc())->where('ends_at', '>', $first->utc())->get();
        $bookings = RoomBooking::with('booker:id,name,name_ar')->where('status', 'confirmed')->where('starts_at', '<', $last->utc())->where('ends_at', '>', $first->utc())->get();
        $seriesSize = RoomBooking::whereIn('series_id', $bookings->pluck('series_id')->filter()->unique())->where('status', 'confirmed')
            ->selectRaw('series_id, count(*) as n')->groupBy('series_id')->pluck('n', 'series_id');

        $events = $sessions->map(fn (ProgramSession $s) => [
            'type' => 'session', 'id' => $s->id, 'room_id' => $s->training_room_id, 'date' => $s->starts_at->timezone($tz)->toDateString(),
            'start' => $s->starts_at->timezone($tz)->format('H:i'), 'end' => $s->ends_at->timezone($tz)->format('H:i'),
            'title' => $s->program?->title_ar ?: $s->program?->title_en, 'program_id' => $s->program_id, 'group' => $s->trainingGroup ? ($s->trainingGroup->title_ar ?: (string) $s->trainingGroup->sequence) : null,
            'color' => $s->trainingGroup?->color, 'day' => $s->sequence, 'trainer' => $s->trainer?->name_ar ?: $s->trainer?->name_en,
        ])->merge($bookings->map(fn (RoomBooking $b) => [
            'type' => 'booking', 'id' => $b->id, 'room_id' => $b->room_id, 'date' => $b->starts_at->timezone($tz)->toDateString(),
            'start' => $b->starts_at->timezone($tz)->format('H:i'), 'end' => $b->ends_at->timezone($tz)->format('H:i'),
            'title' => $b->title, 'purpose' => $b->purpose, 'requested_by' => $b->requested_by, 'reason' => $b->reason, 'booked_by' => $b->booker?->displayName(), 'booked_by_id' => $b->booked_by,
            'series_id' => $b->series_id, 'series_size' => $b->series_id ? (int) ($seriesSize[$b->series_id] ?? 1) : 1, 'recurrence' => $b->recurrence,
        ]))->sortBy(['date', 'start'])->values();

        $days = collect($this->calendar->range($first, $last, false)['days'])->map(fn ($d) => [
            'date' => $d['date'], 'kind' => $d['kind'], 'training_allowed' => $d['training_allowed'], 'events_allowed' => $this->eventsAllowed($d),
            'label' => $d['entry']['title'] ?? $d['weekly']['title'] ?? null,
        ])->values();

        return [
            'month' => $ym, 'hours' => ['first' => self::FIRST_HOUR, 'last' => self::LAST_HOUR],
            'rooms' => $rooms->map(fn ($r) => ['id' => $r->id, 'name' => $r->name_ar ?: $r->name_en, 'type' => $r->room_type ?? 'training', 'tag' => $r->tag, 'capacity' => $r->capacity, 'building' => $r->building, 'floor' => $r->floor, 'layout' => $r->layout])->values(),
            'days' => $days, 'events' => $events,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function dates(string $first, ?string $recurrence, int $count): array
    {
        $step = self::STEPS[$recurrence ?? ''] ?? 0;
        $count = $step ? max(1, min($count, 60)) : 1;
        $d = CarbonImmutable::parse($first);

        return array_map(fn ($i) => $d->addDays($i * $step)->toDateString(), range(0, $count - 1));
    }

    /** @param list<string> $dates */
    private function assertOpen(array $dates): void
    {
        $blocked = collect($dates)->filter(fn ($date) => ! $this->eventsAllowed($this->day($date)))->values();
        if ($blocked->isNotEmpty()) {
            $first = $this->day($blocked[0]);
            throw new BusinessRuleException(__('messages.saber.meeting.holiday', ['dates' => $blocked->take(5)->implode('، '), 'more' => max(0, $blocked->count() - 5), 'label' => $first['entry']['title'] ?? $first['weekly']['title'] ?? __('messages.saber.plan.kind_'.$first['kind'])]), 'holiday', ['dates' => $blocked->all()]);
        }
    }

    /** @param list<string> $dates */
    private function assertFree(string $roomId, array $dates, int $from, int $to, ?string $exceptBookingId = null): void
    {
        $this->assertHours($from, $to);
        $clashes = [];
        foreach ($dates as $date) {
            foreach ($this->rooms->occupants($roomId, $this->at($date, $from), $this->at($date, $to), null, $exceptBookingId) as $h) {
                $clashes[] = ['date' => $date, 'title' => $h['program'] ?? $h['title'], 'from' => CarbonImmutable::parse($h['starts_at'])->timezone(config('app.timezone'))->format('H:i'), 'to' => CarbonImmutable::parse($h['ends_at'])->timezone(config('app.timezone'))->format('H:i')];
            }
        }
        if ($clashes !== []) {
            $list = collect($clashes)->take(4)->map(fn ($c) => "{$c['date']} ({$c['from']}–{$c['to']}) {$c['title']}")->implode('؛ ');
            throw new BusinessRuleException(__('messages.saber.meeting.taken', ['list' => $list]), 'hours_taken', ['clashes' => $clashes]);
        }
    }

    private function assertHours(int $from, int $to): void
    {
        if ($from < self::FIRST_HOUR || $to > self::LAST_HOUR || $to <= $from) {
            throw new BusinessRuleException(__('messages.saber.meeting.hours', ['from' => self::FIRST_HOUR, 'to' => self::LAST_HOUR]), 'outside_meeting_hours');
        }
    }

    private function at(string $date, int $hour): CarbonImmutable
    {
        return CarbonImmutable::parse(sprintf('%s %02d:00', $date, $hour), config('app.timezone'));
    }

    private function day(string $date): array
    {
        return $this->calendar->describe(CarbonImmutable::parse($date), CalendarDay::whereDate('date', $date)->first(), CalendarApproval::whereDate('date', $date)->first());
    }

    /** Working days take events; exam and «normal» days are working days too; a holiday only when it allows events or was approved. */
    private function eventsAllowed(array $day): bool
    {
        return $day['training_allowed'] || $day['allow_events'] || in_array($day['kind'], ['exam', 'normal'], true);
    }
}
