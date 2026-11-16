<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\GroupSeatAllocation;
use App\Models\Registration;
use App\Models\TrainingGroup;
use Illuminate\Support\Facades\DB;

/**
 * Seats of a group split per beneficiary entity (school, school group, department, job group) plus an open pool.
 * A group without allocations is one pool, exactly as before. Unused seats move to the open pool at their release time.
 */
class SeatAllocationService
{
    /** @return list<GroupSeatAllocation> */
    public function allocations(TrainingGroup $group)
    {
        return GroupSeatAllocation::where('group_id', $group->id)->orderBy('priority')->orderBy('created_at')->get();
    }

    /**
     * Replaces the allocations of a group.
     *
     * @param  list<array{entity_type: string, entity_id?: ?string, seats: int, release_at?: ?string, priority?: int}>  $rows
     */
    public function replace(TrainingGroup $group, array $rows): void
    {
        $total = array_sum(array_column($rows, 'seats'));
        if ($total > $group->capacity) {
            throw new BusinessRuleException(__('messages.seats.over_capacity', ['total' => $total, 'capacity' => $group->capacity]), 'seats_over_capacity');
        }
        DB::transaction(function () use ($group, $rows) {
            GroupSeatAllocation::where('group_id', $group->id)->delete();
            foreach ($rows as $i => $r) {
                GroupSeatAllocation::create(['group_id' => $group->id, 'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'] ?? null, 'seats' => $r['seats'], 'release_at' => $r['release_at'] ?? null, 'priority' => $r['priority'] ?? $i]);
            }
        });
    }

    public function hasAllocations(TrainingGroup $group): bool
    {
        return GroupSeatAllocation::where('group_id', $group->id)->exists();
    }

    /**
     * Picks the entity pool a new registration draws from: the employee's school, then their school groups, department and job group,
     * then the open pool. Null means every pool they may use is full (they go to the waiting list).
     *
     * @return array{0: string, 1: ?string}|null
     */
    public function claim(TrainingGroup $group, Employee $employee): ?array
    {
        $rows = $this->allocations($group);
        if ($rows->isEmpty()) {
            return ['open', null];
        }
        $used = Registration::where('training_group_id', $group->id)->whereIn('status', Registration::SEAT_HOLDING)->whereNotNull('seat_entity_type')
            ->get()->groupBy(fn ($r) => $r->seat_entity_type.'|'.($r->seat_entity_id ?? ''))->map->count();

        $groupIds = $employee->school?->groups()->pluck('school_groups.id')->all() ?? [];
        $candidates = [
            ['school', $employee->school_id], ['school_group', null], ['department', $employee->department_id], ['job_group', $employee->job_title_id],
        ];
        foreach ($candidates as [$type, $id]) {
            foreach ($rows->where('entity_type', $type) as $a) {
                if ($type === 'school_group' ? ! in_array($a->entity_id, $groupIds, true) : $a->entity_id !== $id) {
                    continue;
                }
                if (($used[$type.'|'.$a->entity_id] ?? 0) < $a->seats) {
                    return [$type, $a->entity_id];
                }
            }
        }

        $explicitOpen = (int) $rows->where('entity_type', 'open')->sum('seats');
        $open = max(0, $group->capacity - (int) $rows->where('entity_type', '!=', 'open')->sum('seats'));
        $open = max($open, $explicitOpen);

        return ($used['open|'] ?? 0) < $open ? ['open', null] : null;
    }

    /** Seats per pool with how many are taken, for the admission screen and the trainee's own view. */
    public function summary(TrainingGroup $group): array
    {
        $rows = $this->allocations($group);
        $used = Registration::where('training_group_id', $group->id)->whereIn('status', Registration::SEAT_HOLDING)->get()->groupBy(fn ($r) => ($r->seat_entity_type ?? 'open').'|'.($r->seat_entity_id ?? ''))->map->count();
        $pools = $rows->where('entity_type', '!=', 'open')->map(fn ($a) => ['entity_type' => $a->entity_type, 'entity_id' => $a->entity_id, 'seats' => $a->seats, 'taken' => $used[$a->entity_type.'|'.$a->entity_id] ?? 0, 'release_at' => $a->release_at?->toIso8601String(), 'released' => $a->released_at !== null])->values()->all();
        $open = max(0, $group->capacity - (int) $rows->where('entity_type', '!=', 'open')->sum('seats'));

        return ['capacity' => $group->capacity, 'pools' => $pools, 'open' => ['seats' => $open, 'taken' => $used['open|'] ?? 0]];
    }

    /** Hourly: allocations past their release time give their unused seats to the open pool, then waiting people are promoted. @return int allocations released */
    public function releaseDue(RegistrationService $registrations): int
    {
        $n = 0;
        foreach (GroupSeatAllocation::whereNotNull('release_at')->whereNull('released_at')->where('release_at', '<=', now())->with('group.program')->get() as $a) {
            $taken = Registration::where('training_group_id', $a->group_id)->whereIn('status', Registration::SEAT_HOLDING)->where('seat_entity_type', $a->entity_type)->where('seat_entity_id', $a->entity_id)->count();
            $a->update(['seats' => $taken, 'released_at' => now()]);
            $n++;
            if ($a->group?->program) {
                $registrations->promoteFromWaitingList($a->group->program, $a->group);
            }
        }

        return $n;
    }
}
