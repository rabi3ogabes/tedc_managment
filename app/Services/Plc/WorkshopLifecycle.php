<?php

namespace App\Services\Plc;

use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;

/**
 * PLC Phase 5: the operational states move by the clock. A scheduled workshop is ready a day before its first
 * session (or as soon as its first attendance code is shown), in progress when the first session starts, and
 * completed when the last session ends, at which point every session's record is locked and absentees are written
 * down. Each move is recorded as a decision by the workshop's coordinator (or its author) on the system's behalf.
 */
class WorkshopLifecycle
{
    public const READY_HOURS_BEFORE = 24;

    public function __construct(private readonly WorkshopWorkflow $workflow, private readonly WorkshopAttendance $attendance, private readonly WorkshopEvaluation $evaluation) {}

    /** @return array{ready: int, in_progress: int, completed: int, closed: int} */
    public function tick(): array
    {
        $r = ['ready' => 0, 'in_progress' => 0, 'completed' => 0, 'closed' => 0];
        foreach (PlcWorkshop::with('program', 'coordinator')->whereIn('workflow_state', ['scheduled', 'ready', 'in_progress', 'completed', 'awaiting_evaluation'])->get() as $w) {
            $live = ProgramSession::where('program_id', $w->program_id)->where('status', '!=', 'cancelled');
            $first = (clone $live)->min('starts_at');
            $last = (clone $live)->max('ends_at');
            if (! $first || ! $last) {
                continue;
            }
            $first = Carbon::parse($first);
            $last = Carbon::parse($last);
            $actor = $this->actor($w);
            if (! $actor) {
                continue;
            }
            if ($w->workflow_state === 'scheduled' && $first->lte(now()->addHours(self::READY_HOURS_BEFORE))) {
                $this->workflow->move($w, 'ready', $actor, null, ['auto' => 'clock']);
                $r['ready']++;
                $w->refresh();
            }
            if ($w->workflow_state === 'ready' && $first->lte(now())) {
                $this->workflow->move($w, 'in_progress', $actor, null, ['auto' => 'clock']);
                $r['in_progress']++;
                $w->refresh();
            }
            if ($w->workflow_state === 'in_progress' && $last->lte(now())) {
                foreach ((clone $live)->get() as $s) {
                    $this->attendance->lock($w, $s, $actor, true);
                }
                $this->workflow->move($w, 'completed', $actor, null, ['auto' => 'clock']);
                $r['completed']++;
                $this->evaluation->onCompleted($w->refresh(), $actor);
            } elseif (in_array($w->workflow_state, WorkshopEvaluation::SENDABLE, true)) {
                // Session and trainer forms go out as their sessions end; a completed workshop settles into its evaluation state.
                $this->evaluation->tick($w, $actor);
            }
            if ($w->refresh()->workflow_state === 'closed') {
                $r['closed']++;
            }
        }

        return $r;
    }

    private function actor(PlcWorkshop $w): ?User
    {
        return $w->coordinator ?? User::find($w->program?->created_by) ?? User::whereHas('roles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))->first();
    }
}
