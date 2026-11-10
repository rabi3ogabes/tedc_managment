<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProgramSession;
use App\Models\RoomBooking;
use App\Models\TrainingRoom;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Room availability, booking conflicts and best-fit suggestions.
 */
class RoomService
{
    /** Sessions overlapping [start, end) in the room, ignoring cancelled ones and an optional session. */
    public function conflicts(string $roomId, CarbonInterface $start, CarbonInterface $end, ?string $exceptSessionId = null): Collection
    {
        // With "one session per room per day" the whole day of the room is taken, not only the hours of the session.
        [$start, $end] = $this->span($start, $end);

        return ProgramSession::with('program:id,code,title_ar,title_en')
            ->where('training_room_id', $roomId)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($exceptSessionId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->orderBy('starts_at')
            ->get();
    }

    /** Bookings (meetings, exams, events…) overlapping [start, end) in the room. */
    public function bookingConflicts(string $roomId, CarbonInterface $start, CarbonInterface $end, ?string $exceptBookingId = null): Collection
    {
        return RoomBooking::with('booker:id,name,name_ar')->where('room_id', $roomId)->where('status', 'confirmed')->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($exceptBookingId, fn ($q, $id) => $q->where('id', '!=', $id))->orderBy('starts_at')->get();
    }

    /** True when neither a session nor a booking holds the room in that time. */
    public function isFree(string $roomId, CarbonInterface $start, CarbonInterface $end, ?string $exceptSessionId = null): bool
    {
        return $this->conflicts($roomId, $start, $end, $exceptSessionId)->isEmpty() && $this->bookingConflicts($roomId, $start, $end)->isEmpty();
    }

    /** Who holds the room: sessions (with their program and supervisor) and bookings (with the person who booked). @return list<array<string, mixed>> */
    public function occupants(string $roomId, CarbonInterface $start, CarbonInterface $end, ?string $exceptSessionId = null, ?string $exceptBookingId = null): array
    {
        $out = [];
        foreach ($this->conflicts($roomId, $start, $end, $exceptSessionId) as $s) {
            $supervisor = $s->trainingGroup?->supervisor ?? ($s->program?->coordinator_id ? User::find($s->program->coordinator_id) : null);
            $out[] = ['type' => 'session', 'id' => $s->id, 'title' => $s->translate('title'), 'program' => $s->program?->translate('title'), 'supervisor' => $supervisor?->displayName(), 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String()];
        }
        foreach ($this->bookingConflicts($roomId, $start, $end, $exceptBookingId) as $b) {
            $out[] = ['type' => 'booking', 'id' => $b->id, 'title' => $b->title, 'purpose' => $b->purpose, 'supervisor' => $b->booker?->displayName(), 'starts_at' => $b->starts_at->toIso8601String(), 'ends_at' => $b->ends_at->toIso8601String()];
        }

        return $out;
    }

    /** The most people the room may hold: its own capacity, the building's limit and the place's limit, whichever is lowest. */
    public function effectiveCapacity(TrainingRoom $room): int
    {
        $room->loadMissing(['buildingModel', 'place']);

        return (int) min(array_filter([$room->capacity, $room->buildingModel?->capacity_limit, $room->place?->capacity_limit], fn ($v) => $v !== null && $v > 0) ?: [PHP_INT_MAX]);
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface} */
    private function span(CarbonInterface $start, CarbonInterface $end): array
    {
        if (! app(TrainingDaySettings::class)->oneSessionPerRoomPerDay()) {
            return [$start, $end];
        }
        $tz = config('app.timezone');
        $day = $start->copy()->timezone($tz);

        return [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()];
    }

    /**
     * @throws BusinessRuleException when the room is already booked or unavailable
     */
    public function assertBookable(string $roomId, CarbonInterface $start, CarbonInterface $end, ?string $exceptSessionId = null, ?int $attendees = null, ?string $exceptBookingId = null): void
    {
        $room = TrainingRoom::findOrFail($roomId);
        if ($room->status !== 'active') {
            throw new BusinessRuleException(__('messages.room.unavailable', ['room' => $room->translate('name')]), 'room_unavailable');
        }

        $occupants = $this->occupants($roomId, $start, $end, $exceptSessionId, $exceptBookingId);
        if ($occupants) {
            throw new BusinessRuleException(__('messages.room.booked', ['room' => $room->translate('name')]), 'room_conflict', [
                'occupants' => $occupants,
                'sessions' => collect($occupants)->where('type', 'session')->map(fn ($o) => ['id' => $o['id'], 'title' => $o['title'], 'program' => $o['program'], 'starts_at' => $o['starts_at'], 'ends_at' => $o['ends_at']])->values()->all(),
            ]);
        }
        if ($attendees !== null && $attendees > $this->effectiveCapacity($room)) {
            throw new BusinessRuleException(__('messages.room.over_capacity', ['room' => $room->translate('name'), 'capacity' => $this->effectiveCapacity($room), 'needed' => $attendees]), 'room_capacity', ['capacity' => $this->effectiveCapacity($room)]);
        }
    }

    /**
     * Every active room ranked for a slot: available first, then the best fit for the needed
     * capacity, seating layout and equipment.
     *
     * @param  array{capacity?: int|null, layout?: string|null, equipment?: string[], office?: string|null, accessible?: bool}  $need
     */
    public function suggest(CarbonInterface $start, CarbonInterface $end, array $need = [], ?string $exceptSessionId = null): Collection
    {
        $capacity = (int) ($need['capacity'] ?? 0);
        $layout = $need['layout'] ?? null;
        $required = array_values(array_unique($need['equipment'] ?? []));

        [$start, $end] = $this->span($start, $end);
        $booked = ProgramSession::with('program:id,code,title_ar,title_en')
            ->whereNotNull('training_room_id')->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($exceptSessionId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->get()->groupBy('training_room_id');

        return TrainingRoom::where('status', 'active')
            ->when($need['office'] ?? null, fn ($q, $o) => $q->where('office', $o))
            ->when(! empty($need['accessible']), fn ($q) => $q->where('is_accessible', true))
            ->get()
            ->map(function (TrainingRoom $room) use ($capacity, $layout, $required, $booked) {
                $roomCapacity = $room->capacityFor($layout);
                $fits = $capacity === 0 || $roomCapacity >= $capacity;
                $missing = array_values(array_diff($required, $room->equipmentKeys()));
                $supportsLayout = ! $layout || isset($room->layouts[$layout]);
                $clashes = $booked->get($room->id, collect());

                $capacityScore = $fits ? ($capacity ? 40 - (int) round(min(1, ($roomCapacity - $capacity) / max(1, $roomCapacity)) * 20) : 40) : 0;
                $equipmentScore = $required ? (int) round(40 * (count($required) - count($missing)) / count($required)) : 40;
                $layoutScore = $supportsLayout ? 20 : 0;

                return [
                    'room' => $room,
                    'available' => $clashes->isEmpty(),
                    'fits_capacity' => $fits,
                    'capacity_for_layout' => $roomCapacity,
                    'supports_layout' => $supportsLayout,
                    'missing_equipment' => $missing,
                    'score' => $capacityScore + $equipmentScore + $layoutScore,
                    'conflicts' => $clashes->map(fn ($s) => [
                        'id' => $s->id, 'title' => $s->translate('title'), 'program' => $s->program?->translate('title'),
                        'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                    ])->values()->all(),
                ];
            })
            ->sortBy([['available', 'desc'], ['fits_capacity', 'desc'], ['score', 'desc'], fn ($a, $b) => strcmp($a['room']->name_ar, $b['room']->name_ar)])
            ->values();
    }
}
