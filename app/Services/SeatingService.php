<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\SeatingPlan;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;

/** Seat grid of a room for a session or group: designed on screen, filled by hand or automatically. */
class SeatingService
{
    public function plan(TrainingRoom $room, ?string $sessionId, ?string $groupId): ?SeatingPlan
    {
        return SeatingPlan::where('room_id', $room->id)->when($sessionId, fn ($q) => $q->where('session_id', $sessionId), fn ($q) => $q->whereNull('session_id'))->when($groupId, fn ($q) => $q->where('group_id', $groupId), fn ($q) => $q->whereNull('group_id'))->first();
    }

    /** @param  array{rows: int, cols: int, blocked?: list<string>, labels?: array<string, string>}  $layout */
    public function saveLayout(TrainingRoom $room, ?string $sessionId, ?string $groupId, array $layout, ?array $assignments = null): SeatingPlan
    {
        $layout['blocked'] = array_values(array_unique($layout['blocked'] ?? []));
        $existing = $this->plan($room, $sessionId, $groupId);
        $assignments ??= $existing?->assignments ?? [];
        $seen = [];
        foreach ($assignments as $seat => $registrationId) {
            [$r, $c] = array_map('intval', explode(',', $seat) + [0, 0]);
            if ($r >= $layout['rows'] || $c >= $layout['cols'] || in_array($seat, $layout['blocked'], true)) {
                unset($assignments[$seat]);   // the seat no longer exists (smaller grid or blocked)

                continue;
            }
            if (isset($seen[$registrationId])) {
                throw new BusinessRuleException(__('messages.seating.duplicate'), 'seating_duplicate');
            }
            $seen[$registrationId] = true;
        }
        $attrs = ['layout' => $layout, 'assignments' => $assignments];

        return $existing ? tap($existing)->update($attrs) : SeatingPlan::create(['room_id' => $room->id, 'session_id' => $sessionId, 'group_id' => $groupId] + $attrs);
    }

    /** Fills the free seats in row order: alphabetical, grouped by school, or shuffled. */
    public function auto(TrainingRoom $room, ?string $sessionId, ?string $groupId, string $mode): SeatingPlan
    {
        $plan = $this->plan($room, $sessionId, $groupId) ?? throw new BusinessRuleException(__('messages.seating.no_layout'), 'seating_no_layout');
        $people = $this->people($sessionId, $groupId);
        $people = match ($mode) {
            'school' => $people->sortBy(fn ($r) => [$r->employee->school?->translate('name'), $r->employee->user?->displayName()])->values(),
            'random' => $people->shuffle()->values(),
            default => $people->sortBy(fn ($r) => mb_strtolower((string) $r->employee->user?->displayName()))->values(),
        };
        $layout = $plan->layout;
        $seats = [];
        for ($r = 0; $r < $layout['rows']; $r++) {
            for ($c = 0; $c < $layout['cols']; $c++) {
                if (! in_array("{$r},{$c}", $layout['blocked'] ?? [], true)) {
                    $seats[] = "{$r},{$c}";
                }
            }
        }
        if (count($seats) < $people->count()) {
            throw new BusinessRuleException(__('messages.seating.insufficient', ['seats' => count($seats), 'people' => $people->count()]), 'seats_insufficient');
        }
        $assignments = [];
        foreach ($people as $i => $reg) {
            $assignments[$seats[$i]] = $reg->id;
        }
        $plan->update(['assignments' => $assignments]);

        return $plan;
    }

    /** The grid with a name in every taken seat, for the designer and the printout. */
    public function present(TrainingRoom $room, ?SeatingPlan $plan): array
    {
        $names = $plan?->assignments ? Registration::with('employee.user:id,name,name_ar')->whereIn('id', array_values($plan->assignments))->get()->keyBy('id') : collect();

        return [
            'room' => ['id' => $room->id, 'name' => $room->translate('name'), 'capacity' => app(RoomService::class)->effectiveCapacity($room)],
            'layout' => $plan?->layout ?? ['rows' => 0, 'cols' => 0, 'blocked' => []],
            'assignments' => $plan?->assignments ?? [],
            'seats' => collect($plan?->assignments ?? [])->map(fn ($id) => ['registration_id' => $id, 'name' => $names->get($id)?->employee?->user?->displayName()])->all(),
        ];
    }

    private function people(?string $sessionId, ?string $groupId)
    {
        $program = null;
        $group = null;
        if ($sessionId) {
            $s = ProgramSession::with('program')->findOrFail($sessionId);
            $program = $s->program;
            $group = $s->training_group_id && ! $program->hasSingleGroup() ? $s->training_group_id : null;
        } elseif ($groupId) {
            $g = TrainingGroup::with('program')->findOrFail($groupId);
            $program = $g->program;
            $group = $program->hasSingleGroup() ? null : $g->id;
        }

        return Registration::with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en'])->where('program_id', $program?->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->when($group, fn ($q) => $q->where('training_group_id', $group))->get();
    }
}
