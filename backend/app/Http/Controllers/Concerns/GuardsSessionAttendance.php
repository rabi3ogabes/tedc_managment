<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ProgramSession;
use App\Models\TrainingGroup;
use App\Services\Duties\MyDuties;
use App\Services\ProgramGrantService;

/**
 * Who may open and record the attendance of a session, in one place for every screen that does it (the sheet, the QR
 * code, the kiosk, staff scanning, the trainers' attendance and the exports).
 *
 * A session's attendance is for the people who run it: centre staff, a trainer of the session (it is theirs, or they
 * teach its group or its programme), the supervisor of its group, or somebody the head of training gave the attendance
 * right on this programme. Holding the attendance right for the role does not open another group's session.
 */
trait GuardsSessionAttendance
{
    protected function authorizeSessionAttendance(ProgramSession $session): void
    {
        $user = $this->user();
        if ($this->isCenterStaff($user)) {
            return;
        }

        $runs = app(MyDuties::class)->runs($user, $session);
        // Or the head of training gave this person the attendance right on this program.
        $granted = app(ProgramGrantService::class)->allows($user, $session->program_id, 'attendance.mark');
        abort_unless($runs || $granted, 403, __('messages.grants.attendance_denied'));
    }

    /** The same rule for what is read by group (its attendance export): the group's supervisor, its trainers, or the grant on its programme. */
    protected function authorizeGroupAttendance(TrainingGroup $group): void
    {
        $user = $this->user();
        if ($this->isCenterStaff($user)) {
            return;
        }

        $runs = app(MyDuties::class)->runsGroup($user, $group);
        $granted = app(ProgramGrantService::class)->allows($user, $group->program_id, 'attendance.mark');
        abort_unless($runs || $granted, 403, __('messages.grants.attendance_denied'));
    }
}
