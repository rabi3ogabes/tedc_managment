<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProgramSession;
use App\Models\TrainingRoom;
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
        return ProgramSession::with('program:id,code,title_ar,title_en')
            ->where('training_room_id', $roomId)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($exceptSessionId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @throws BusinessRuleException when the room is already booked or unavailable
     */
    public function assertBookable(string $roomId, CarbonInterface $start, CarbonInterface $end, ?string $exceptSessionId = null): void
    {
        $room = TrainingRoom::findOrFail($roomId);
        if ($room->status !== 'active') {
            throw new BusinessRuleException(__('messages.room.unavailable', ['room' => $room->translate('name')]), 'room_unavailable');
        }

        $conflicts = $this->conflicts($roomId, $start, $end, $exceptSessionId);
        if ($conflicts->isNotEmpty()) {
            throw new BusinessRuleException(__('messages.room.booked', ['room' => $room->translate('name')]), 'room_conflict', [
                'sessions' => $conflicts->map(fn ($s) => [
                    'id' => $s->id, 'title' => $s->translate('title'), 'program' => $s->program?->translate('title'),
                    'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                ])->all(),
            ]);
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
