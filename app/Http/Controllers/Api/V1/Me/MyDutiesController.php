<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\TrainingGroup;
use App\Services\Duties\MyDuties;
use App\Services\ProgramGrantService;
use Illuminate\Http\JsonResponse;

/**
 * «برامجي»: the programme groups the signed-in person supervises or teaches, each with its sessions, the attendance of
 * every session, and the programme's two forms (the trainee survey and the trainer's self-reflection). It asks for no
 * right of its own: it only ever lists the person's own assignments, and what they may do with a session is decided
 * where the session is opened (SessionController).
 */
class MyDutiesController extends Controller
{
    public function index(MyDuties $duties): JsonResponse
    {
        $rows = $duties->rows($this->user());
        $count = fn (string $capacity) => count(array_filter($rows, fn ($r) => in_array($capacity, $r['capacities'], true)));

        return response()->json(['data' => [
            'groups' => $rows,
            'summary' => [
                'total' => count($rows), 'supervising' => $count('supervisor'), 'teaching' => $count('trainer'),
                'running' => count(array_filter($rows, fn ($r) => $r['phase'] === 'running')),
                'upcoming' => count(array_filter($rows, fn ($r) => $r['phase'] === 'upcoming')),
                // Sessions happening now or later today, across every group.
                'today' => array_sum(array_map(fn ($r) => count(array_filter($r['sessions'], fn ($s) => in_array($s['when'], ['now', 'today'], true))), $rows)),
            ],
            'now' => now()->toIso8601String(),
        ]]);
    }

    /**
     * The trainees of one of the person's own groups, with how much of it each attended. The names are what the attendance
     * sheet of the group's sessions already shows, so it asks for what the sheet asks for: the group is theirs to run, and
     * they hold the attendance right (for their role, or given on this programme). Another group is not found.
     */
    public function show(TrainingGroup $group, MyDuties $duties, ProgramGrantService $grants): JsonResponse
    {
        $user = $this->user();
        abort_unless($duties->supervisedGroupIds($user)->merge($duties->taughtGroupIds($user))->contains($group->id), 404);
        abort_unless($user->hasPermission('attendance.manage') || $grants->allows($user, $group->program_id, 'attendance.mark'), 403, __('messages.grants.attendance_denied'));

        return response()->json(['data' => ['id' => $group->id] + $duties->trainees($group)]);
    }
}
