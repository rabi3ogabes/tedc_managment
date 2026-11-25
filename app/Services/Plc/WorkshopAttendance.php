<?php

namespace App\Services\Plc;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\AttendanceAttempt;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PLC Phase 5: attendance of a workshop session on the engine's attendance service. The live roster with the
 * rotating code, the channel each person attended through (venue by code, remote by join), late and left-early
 * under the PLC policy, code revocation, corrections that always carry a reason, a lock per session that fixes
 * the record (absentees written down) and the workshop report. Every action is a decision in the log.
 */
class WorkshopAttendance
{
    public const PRESENT = ['present', 'late', 'left_early'];

    public const CORRECTABLE = ['present', 'late', 'left_early', 'absent', 'excused'];

    public function __construct(private readonly AttendanceService $attendance, private readonly WorkshopWorkflow $workflow, private readonly PlcSettings $settings) {}

    public function mayManage(User $user): bool
    {
        return $user->hasPermission('plc.attendance.manage') || $user->hasPermission('plc.manage') || $user->hasPermission('attendance.manage');
    }

    // Roster -------------------------------------------------------------------------------------------------------

    public function roster(PlcWorkshop $w, ProgramSession $s, User $user): array
    {
        $this->assertSession($w, $s);
        $s->loadMissing('room:id,name_ar,name_en', 'attendanceLocker:id,name,name_ar');
        $ended = $s->ends_at->isPast();
        $rows = $this->seated($w)->map(function (Registration $r) use ($s, $ended) {
            $a = $r->attendanceFor($s->id);
            $status = $a?->status ?? ($ended ? 'absent' : 'expected');

            return [
                'registration_id' => $r->id, 'employee_id' => $r->employee_id, 'name' => $r->employee?->user?->displayName(), 'school' => $r->employee?->school?->name_ar,
                'status' => $status, 'channel' => $a?->channel, 'method' => $a?->method, 'check_in_at' => $a?->check_in_at, 'check_out_at' => $a?->check_out_at, 'minutes' => $a?->minutes_attended ?? 0,
                'late_by' => $a?->check_in_at && $a->check_in_at->gt($s->starts_at) ? $s->starts_at->diffInMinutes($a->check_in_at) : null,
                'corrected' => (bool) $a?->corrected_at, 'correction_reason' => $a?->correction_reason, 'location_status' => $a?->location_status,
            ];
        })->values();
        $manage = $this->mayManage($user);
        $locked = (bool) $s->attendance_locked_at;

        return [
            'session' => ['id' => $s->id, 'sequence' => $s->sequence, 'title_ar' => $s->title_ar, 'title_en' => $s->title_en, 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'mode' => $s->mode ?: 'in_person', 'status' => $s->status,
                'room' => $s->room?->name_ar, 'location_text' => $s->location_text, 'has_link' => (bool) $s->online_url, 'ended' => $ended, 'started' => $s->starts_at->isPast(),
                'locked_at' => $s->attendance_locked_at, 'locked_by' => $s->attendanceLocker?->displayName()],
            'workshop' => ['id' => $w->program_id, 'state' => $w->workflow_state, 'title_ar' => $w->program->title_ar, 'title_en' => $w->program->title_en],
            'policy' => ['late_minutes' => (int) $this->settings->get('late_minutes'), 'early_leave_minutes' => (int) $this->settings->get('early_leave_minutes'), 'rotation_seconds' => max(10, (int) config('tedc.attendance.qr_rotation_seconds'))],
            'counts' => [
                'expected' => $rows->count(), 'present' => $rows->whereIn('status', self::PRESENT)->count(), 'late' => $rows->where('status', 'late')->count(), 'left_early' => $rows->where('status', 'left_early')->count(),
                'absent' => $rows->where('status', 'absent')->count(), 'excused' => $rows->where('status', 'excused')->count(), 'pending' => $rows->where('status', 'expected')->count(),
                'in_person' => $rows->where('channel', 'in_person')->count(), 'remote' => $rows->where('channel', 'remote')->count(),
            ],
            'rows' => $rows->all(),
            'qr' => $manage && $this->codeApplies($s) ? $this->attendance->currentQr($s) : null,
            'attempts' => $manage ? AttendanceAttempt::with('employee.user:id,name,name_ar')->where('program_session_id', $s->id)->where('outcome', 'rejected')->latest()->limit(20)->get()
                ->map(fn ($a) => ['at' => $a->created_at, 'name' => $a->employee?->user?->displayName(), 'code' => $a->code, 'message' => $a->message])->values()->all() : [],
            'can' => ['manage' => $manage, 'correct' => $manage && (! $locked || $user->hasPermission('plc.manage')), 'lock' => $manage && ! $locked, 'unlock' => $locked && $user->hasPermission('plc.manage'), 'code' => $manage && $this->codeApplies($s)],
        ];
    }

    /** The rotating code for the venue screen; the first code of a scheduled workshop makes it ready. */
    public function issueCode(PlcWorkshop $w, ProgramSession $s, User $by): array
    {
        $this->assertSession($w, $s);
        abort_unless($this->mayManage($by), 403);
        if (! $this->codeApplies($s)) {
            throw new BusinessRuleException(__('messages.plc.code_mode'), 'plc_code_mode');
        }
        if ($w->workflow_state === 'scheduled') {
            $this->workflow->move($w, 'ready', $by, null, ['auto' => 'code_issued', 'session_id' => $s->id]);
        }

        return $this->attendance->currentQr($s) + ['state' => $w->fresh()->workflow_state];
    }

    /** A new secret: every code shown or photographed so far stops working at once. */
    public function revoke(PlcWorkshop $w, ProgramSession $s, User $by, ?string $reason = null): array
    {
        $this->assertSession($w, $s);
        abort_unless($this->mayManage($by), 403);
        $s->forceFill(['qr_secret' => Str::random(48)])->save();
        $this->workflow->decision($w, 'attendance', 'code_revoked', $reason, ['session_id' => $s->id], $by);

        return $this->attendance->currentQr($s->fresh());
    }

    // Corrections and locking --------------------------------------------------------------------------------------

    /** A correction by hand always carries a reason; after the lock only the centre may still correct. */
    public function correct(PlcWorkshop $w, ProgramSession $s, Registration $r, User $by, string $status, ?int $minutes, ?string $reason): Attendance
    {
        $this->assertSession($w, $s);
        abort_unless($this->mayManage($by) && $r->program_id === $w->program_id, 403);
        if (! in_array($status, self::CORRECTABLE, true)) {
            throw new BusinessRuleException(__('messages.plc.correction_status'), 'plc_correction_status');
        }
        if (trim((string) $reason) === '') {
            throw new BusinessRuleException(__('messages.plc.correction_reason'), 'plc_correction_reason');
        }
        if ($s->attendance_locked_at && ! $by->hasPermission('plc.manage')) {
            throw new BusinessRuleException(__('messages.plc.attendance_locked'), 'plc_attendance_locked');
        }
        $before = $r->attendanceFor($s->id)?->only(['status', 'minutes_attended', 'check_in_at', 'check_out_at', 'channel']);
        $full = $s->durationMinutes();
        if ($status === 'left_early') {
            $minutes = max(1, min($full - 1, (int) ($minutes ?? (int) round($full / 2))));
        }
        $a = $this->attendance->mark($s, $r, $status === 'left_early' ? 'present' : $status, $by, $minutes);
        $patch = ['correction_reason' => mb_substr(trim((string) $reason), 0, 500), 'corrected_by' => $by->id, 'corrected_at' => now()];
        if ($status === 'left_early') {
            $patch += ['status' => 'left_early', 'minutes_attended' => $minutes, 'check_in_at' => $s->starts_at, 'check_out_at' => $s->starts_at->copy()->addMinutes($minutes), 'left_early_at' => $s->starts_at->copy()->addMinutes($minutes)];
        }
        $a->forceFill($patch)->save();
        if ($status === 'left_early') {
            $this->attendance->recalculate($r);
        }
        $this->workflow->decision($w, 'attendance', 'corrected', $reason, ['session_id' => $s->id, 'registration_id' => $r->id, 'before' => $before, 'after' => ['status' => $status, 'minutes' => $a->fresh()->minutes_attended]], $by);

        return $a->fresh();
    }

    /** Fixes the record: everyone seated without a record is written down as absent, then nothing changes without the centre. */
    public function lock(PlcWorkshop $w, ProgramSession $s, User $by, bool $auto = false): int
    {
        $this->assertSession($w, $s);
        abort_unless($auto || $this->mayManage($by), 403);
        if ($s->attendance_locked_at) {
            return 0;
        }
        $written = 0;
        DB::transaction(function () use ($w, $s, $by, $auto, &$written) {
            foreach ($this->seated($w) as $r) {
                if (! $r->attendanceFor($s->id)) {
                    Attendance::create(['program_session_id' => $s->id, 'registration_id' => $r->id, 'employee_id' => $r->employee_id, 'status' => 'absent', 'method' => 'manual', 'location_status' => 'system', 'minutes_attended' => 0, 'recorded_by' => $auto ? null : $by->id]);
                    $written++;
                }
                // Everyone's rate is fixed with the session: a check-in without a check-out now counts up to the session's end.
                $this->attendance->recalculate($r);
            }
            $s->forceFill(['attendance_locked_at' => now(), 'attendance_locked_by' => $auto ? null : $by->id])->save();
            $this->workflow->decision($w, 'attendance', $auto ? 'locked_automatically' : 'locked', null, ['session_id' => $s->id, 'absent_written' => $written], $by);
        });

        return $written;
    }

    public function unlock(PlcWorkshop $w, ProgramSession $s, User $by, ?string $reason): void
    {
        $this->assertSession($w, $s);
        abort_unless($by->hasPermission('plc.manage'), 403);
        if (trim((string) $reason) === '') {
            throw new BusinessRuleException(__('messages.plc.reason_required'), 'plc_reason_required');
        }
        $s->forceFill(['attendance_locked_at' => null, 'attendance_locked_by' => null])->save();
        $this->workflow->decision($w, 'attendance', 'unlocked', $reason, ['session_id' => $s->id], $by);
    }

    // Report -------------------------------------------------------------------------------------------------------

    /** Every seated person against every live session, with per-session rates, the overall rate and the rate per school. */
    public function report(PlcWorkshop $w): array
    {
        $sessions = ProgramSession::where('program_id', $w->program_id)->where('status', '!=', 'cancelled')->orderBy('starts_at')->get();
        $seated = $this->seated($w);
        $rows = $seated->map(function (Registration $r) use ($sessions) {
            $cells = $sessions->map(function (ProgramSession $s) use ($r) {
                $a = $r->attendanceFor($s->id);

                return ['session_id' => $s->id, 'status' => $a?->status ?? ($s->ends_at->isPast() ? 'absent' : 'expected'), 'minutes' => $a?->minutes_attended ?? 0, 'channel' => $a?->channel];
            })->values()->all();

            return ['registration_id' => $r->id, 'name' => $r->employee?->user?->displayName(), 'school' => $r->employee?->school?->name_ar, 'school_id' => $r->employee?->school_id, 'percent' => (float) ($r->attendance_percent ?? 0), 'cells' => $cells];
        })->values();
        $perSession = $sessions->map(function (ProgramSession $s, $i) use ($rows) {
            $statuses = $rows->map(fn ($r) => $r['cells'][$i]['status']);
            $present = $statuses->filter(fn ($x) => in_array($x, self::PRESENT, true))->count();

            return ['id' => $s->id, 'sequence' => $s->sequence, 'title_ar' => $s->title_ar, 'starts_at' => $s->starts_at, 'mode' => $s->mode ?: 'in_person', 'locked' => (bool) $s->attendance_locked_at,
                'expected' => $rows->count(), 'present' => $present, 'late' => $statuses->filter(fn ($x) => $x === 'late')->count(), 'left_early' => $statuses->filter(fn ($x) => $x === 'left_early')->count(),
                'absent' => $statuses->filter(fn ($x) => $x === 'absent')->count(), 'excused' => $statuses->filter(fn ($x) => $x === 'excused')->count(), 'rate' => $rows->count() ? round($present / $rows->count() * 100, 1) : null];
        })->values()->all();
        $bySchool = $rows->groupBy('school_id')->map(fn (Collection $g) => ['school' => $g->first()['school'], 'people' => $g->count(), 'percent' => round((float) $g->avg('percent'), 1)])->values()->all();

        return [
            'sessions' => $perSession, 'rows' => $rows->all(), 'by_school' => $bySchool,
            'overall' => ['people' => $rows->count(), 'percent' => $rows->count() ? round((float) $rows->avg('percent'), 1) : null, 'min_attendance_percent' => $w->program->min_attendance_percent, 'meeting' => $rows->filter(fn ($r) => $r['percent'] >= (float) ($w->program->min_attendance_percent ?? 0))->count()],
        ];
    }

    public function csv(PlcWorkshop $w): string
    {
        $r = $this->report($w);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['الاسم', 'المدرسة', 'نسبة الحضور'], array_map(fn ($s) => 'ج'.$s['sequence'].' '.$s['starts_at']->format('Y-m-d'), $r['sessions'])));
        foreach ($r['rows'] as $row) {
            fputcsv($out, array_merge([$row['name'], $row['school'], $row['percent']], array_map(fn ($c) => $c['status'].($c['minutes'] ? ' ('.$c['minutes'].')' : ''), $row['cells'])));
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    // Helpers ------------------------------------------------------------------------------------------------------

    private function seated(PlcWorkshop $w): Collection
    {
        return Registration::with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en', 'attendance'])->where('program_id', $w->program_id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get()->sortBy(fn ($r) => $r->employee?->user?->displayName())->values();
    }

    private function codeApplies(ProgramSession $s): bool
    {
        return in_array($s->mode ?: 'in_person', ['in_person', 'hybrid'], true) && $s->status !== 'cancelled';
    }

    private function assertSession(PlcWorkshop $w, ProgramSession $s): void
    {
        abort_unless($s->program_id === $w->program_id, 404);
    }
}
