<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AbsenceAlert;
use App\Models\AbsenceExcuse;
use App\Models\Attendance;
use App\Models\AttendanceLeave;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/** Absence thresholds, excuses decided by the direct manager, and leaves (استئذان). */
class AbsenceService
{
    public function __construct(private readonly AttendanceService $attendance, private readonly AttendanceSettings $settings, private readonly NotificationService $notifications, private readonly RegistrationService $registrations, private readonly FileStorage $files) {}

    // Thresholds -------------------------------------------------------------------------------------------------

    /** Share of the held (finished) sessions the trainee missed, excused days left out unless the policy counts them as attended. */
    public function absencePercent(Registration $r): float
    {
        $sessions = ProgramSession::where('program_id', $r->program_id)->where('status', '!=', 'cancelled')->where('ends_at', '<', now())
            ->when($r->training_group_id && ! $r->program->hasSingleGroup(), fn ($q) => $q->where('training_group_id', $r->training_group_id))->get();
        $records = Attendance::where('registration_id', $r->id)->get()->keyBy('program_session_id');
        $held = 0;
        $missed = 0;
        foreach ($sessions as $s) {
            $rec = $records->get($s->id);
            $d = $s->durationMinutes();
            if ($rec?->status === 'excused') {
                continue;
            }
            $held += $d;
            $missed += $rec && $rec->status !== 'absent' ? max(0, $d - min($d, (int) $rec->minutes_attended)) : $d;
        }

        return $held ? round($missed / $held * 100, 2) : 0.0;
    }

    /** Hourly: announce each threshold once. @return int alerts created */
    public function monitor(): int
    {
        $created = 0;
        $cfg = $this->settings->all();
        $regs = Registration::with(['program', 'trainingGroup', 'employee.user'])->whereIn('status', [Registration::STATUS_APPROVED])
            ->whereHas('program.sessions', fn ($q) => $q->where('ends_at', '<', now()))->get();

        foreach ($regs as $r) {
            $pct = $this->absencePercent($r);
            $breachAt = (float) ($cfg['absence_breach_percent'] ?? max(1, 100 - (float) $r->program->min_attendance_percent));
            $warnAt = min((float) $cfg['absence_warning_percent'], $breachAt > 1 ? $breachAt - 0.01 : $breachAt);
            $levels = array_filter(['warning' => $pct >= $warnAt && $pct > 0, 'breach' => $pct > $breachAt]);
            $new = [];
            foreach (array_keys($levels) as $level) {
                $alert = AbsenceAlert::firstOrCreate(['registration_id' => $r->id, 'level' => $level], ['absence_percent' => $pct]);
                if ($alert->wasRecentlyCreated) {
                    $new[] = $alert;
                    $created++;
                }
            }
            if ($new) {
                $this->announce(end($new), $r);
            }
        }

        return $created;
    }

    public function announce(AbsenceAlert $alert, Registration $r): void
    {
        $r->loadMissing(['program', 'trainingGroup', 'employee.user']);
        $program = $r->program;
        $name = $r->employee->user?->displayName('ar') ?? '—';
        $pct = $alert->absence_percent;
        $breach = $alert->level === 'breach';
        $event = $breach ? 'attendance.absence_breach' : 'attendance.absence_warning';
        $note = $alert->supervisor_note ? "\n".$alert->supervisor_note : '';
        $ar = ($breach ? "تجاوز غيابك الحد المسموح في «{$program->title_ar}» ({$pct}%)." : "اقترب غيابك من الحد المسموح في «{$program->title_ar}» ({$pct}%).").$note;
        $en = ($breach ? "Your absence passed the allowed limit in \"{$program->title_en}\" ({$pct}%)." : "Your absence is approaching the limit in \"{$program->title_en}\" ({$pct}%).").$note;
        $title = $breach ? ['ar' => 'تجاوز الغياب الحد المسموح', 'en' => 'Absence limit exceeded'] : ['ar' => 'تنبيه غياب', 'en' => 'Absence warning'];
        $data = ['detail_ar' => $ar, 'detail_en' => $en, 'registration_id' => $r->id, 'program_id' => $program->id];

        $this->notifications->send($r->employee->user_id, $event, $title, ['ar' => $ar, 'en' => $en], $data);
        $staff = array_values(array_filter(array_unique([$r->trainingGroup?->supervisor_id, $program->coordinator_id])));
        $staffBody = ['ar' => "{$name}: غياب {$pct}% في «{$program->title_ar}».", 'en' => "{$r->employee->user?->displayName('en')}: {$pct}% absence in \"{$program->title_en}\"."];
        $staff && $this->notifications->broadcast($staff, $event, $title, $staffBody, $data + ['detail_ar' => $staffBody['ar'], 'detail_en' => $staffBody['en'], 'route' => '/admin/absence']);
        if ($breach) {
            $managerId = $r->manager_id ?? $this->registrations->resolveManager($r->employee)?->id;
            $managerId && $this->notifications->send($managerId, $event, $title, ['ar' => "{$name}: {$ar}", 'en' => "{$r->employee->user?->displayName('en')}: {$en}"], $data);
        }
        $alert->update(['notified_at' => now()]);
    }

    // Excuses ----------------------------------------------------------------------------------------------------

    /** @param  list<UploadedFile>  $files */
    public function submitExcuse(Registration $r, User $by, array $data, array $files = []): AbsenceExcuse
    {
        abort_unless($r->employee->user_id === $by->id, 404);
        if (empty($data['session_id']) && (empty($data['from_date']) || empty($data['to_date']))) {
            throw new BusinessRuleException(__('messages.excuse.when_required'), 'excuse_when_required');
        }
        if (! empty($data['session_id']) && ! ProgramSession::where('program_id', $r->program_id)->whereKey($data['session_id'])->exists()) {
            throw new BusinessRuleException(__('messages.excuse.bad_session'), 'excuse_bad_session');
        }
        $managerId = $r->manager_id ?? $this->registrations->resolveManager($r->employee)?->id;
        $excuse = AbsenceExcuse::create($data + ['registration_id' => $r->id, 'employee_id' => $r->employee_id, 'status' => 'pending', 'manager_id' => $managerId]);
        $paths = array_map(fn (UploadedFile $f) => ['name' => $f->getClientOriginalName(), 'path' => $this->files->upload($f, 'documents', 'excuses/'.$excuse->id)], $files);
        $paths && $excuse->update(['attachments' => $paths]);

        $name = $by->displayName('ar');
        $program = $r->program;
        $ids = array_filter([$managerId]) ?: $this->usersWith('excuses.decide');
        $ids && $this->notifications->broadcast($ids, 'excuse.submitted', ['ar' => 'عذر غياب بانتظار قرارك', 'en' => 'An absence excuse awaits your decision'],
            ['ar' => "{$name} قدّم عذراً عن «{$program->title_ar}».", 'en' => "{$by->displayName('en')} submitted an excuse for \"{$program->title_en}\"."], ['detail_ar' => "{$name} قدّم عذراً عن «{$program->title_ar}»", 'detail_en' => "{$by->displayName('en')} submitted an excuse for \"{$program->title_en}\"", 'excuse_id' => $excuse->id, 'route' => '/admin/approvals']);

        return $excuse;
    }

    public function decideExcuse(AbsenceExcuse $excuse, User $by, string $decision, ?string $note): AbsenceExcuse
    {
        if ($excuse->status !== 'pending') {
            throw new BusinessRuleException(__('messages.excuse.decided'), 'excuse_decided');
        }
        if ($decision === 'rejected' && ! filled($note)) {
            throw new BusinessRuleException(__('messages.assignment.reason_required'), 'reason_required');
        }
        $excuse->update(['status' => $decision, 'decided_at' => now(), 'decision_note' => $note, 'manager_id' => $by->id]);
        $r = $excuse->registration()->with(['program', 'employee.user'])->first();
        if ($decision === 'approved') {
            foreach ($this->sessionsOf($excuse, $r) as $session) {
                Attendance::updateOrCreate(['program_session_id' => $session->id, 'registration_id' => $r->id], ['employee_id' => $r->employee_id, 'status' => 'excused', 'method' => 'excuse', 'excuse_id' => $excuse->id, 'minutes_attended' => 0, 'location_status' => 'manual', 'recorded_by' => $by->id]);
            }
            $this->attendance->recalculate($r);
        }
        [$ar, $en] = $decision === 'approved' ? ['اعتُمد عذرك', 'Your excuse was approved'] : ['لم يُعتمد عذرك', 'Your excuse was not approved'];
        $this->notifications->send($r->employee->user_id, 'excuse.decided', ['ar' => 'قرار بشأن عذر الغياب', 'en' => 'Decision on your absence excuse'],
            ['ar' => "{$ar} عن «{$r->program->title_ar}»".($note ? " — {$note}" : '').'.', 'en' => "{$en} for \"{$r->program->title_en}\"".($note ? " — {$note}" : '').'.'], ['detail_ar' => "{$ar} عن «{$r->program->title_ar}»", 'detail_en' => "{$en} for \"{$r->program->title_en}\"", 'registration_id' => $r->id]);

        return $excuse;
    }

    /** @return Collection<int, ProgramSession> */
    private function sessionsOf(AbsenceExcuse $excuse, Registration $r)
    {
        if ($excuse->session_id) {
            return ProgramSession::whereKey($excuse->session_id)->get();
        }

        return ProgramSession::where('program_id', $r->program_id)->where('status', '!=', 'cancelled')->whereDate('starts_at', '>=', $excuse->from_date)->whereDate('starts_at', '<=', $excuse->to_date)->get();
    }

    // Leaves -----------------------------------------------------------------------------------------------------

    /** @param  list<UploadedFile>  $files */
    public function recordLeave(Attendance $a, User $by, array $data, array $files = []): AttendanceLeave
    {
        $a->loadMissing(['session', 'registration.employee.user', 'registration.program']);
        $minutes = $data['minutes'] ?? $this->minutesBetween($data['from_time'] ?? null, $data['to_time'] ?? null);
        if ($minutes < 1 || $minutes > max(0, (int) $a->minutes_attended)) {
            throw new BusinessRuleException(__('messages.leave.bad_minutes'), 'leave_bad_minutes');
        }
        $leave = AttendanceLeave::create($data + ['attendance_id' => $a->id, 'minutes' => $minutes, 'entered_by' => $by->id]);
        $paths = array_map(fn (UploadedFile $f) => ['name' => $f->getClientOriginalName(), 'path' => $this->files->upload($f, 'documents', 'leaves/'.$leave->id)], $files);
        $paths && $leave->update(['attachments' => $paths]);
        $this->refreshLeaves($a, $a->minutes_attended - $minutes);

        if ($this->settings->all()['leave_notifies_trainee']) {
            $program = $a->registration->program;
            $this->notifications->send($a->registration->employee->user_id, 'leave.recorded', ['ar' => 'سُجّل استئذان', 'en' => 'A leave was recorded'],
                ['ar' => "سُجّل لك استئذان {$minutes} دقيقة في «{$program->title_ar}».", 'en' => "A {$minutes}-minute leave was recorded for you in \"{$program->title_en}\"."], ['detail_ar' => "استئذان {$minutes} دقيقة في «{$program->title_ar}»", 'detail_en' => "{$minutes}-minute leave in \"{$program->title_en}\"", 'registration_id' => $a->registration_id]);
            $leave->update(['notified_at' => now()]);
        }

        return $leave;
    }

    public function deleteLeave(AttendanceLeave $leave): void
    {
        $a = $leave->attendance()->with('session')->first();
        $minutes = $leave->minutes;
        $leave->delete();
        $this->refreshLeaves($a, min($a->session->durationMinutes(), (int) $a->minutes_attended + $minutes));
    }

    private function refreshLeaves(Attendance $a, int $minutesAttended): void
    {
        $a->update(['minutes_attended' => max(0, $minutesAttended), 'leave_minutes' => (int) AttendanceLeave::where('attendance_id', $a->id)->sum('minutes')]);
        $this->attendance->recalculate($a->registration);
    }

    private function minutesBetween(?string $from, ?string $to): int
    {
        return $from && $to ? max(0, (int) ((strtotime($to) - strtotime($from)) / 60)) : 0;
    }

    /** @return list<string> */
    private function usersWith(string $permission): array
    {
        return User::whereHas('roles.permissions', fn ($q) => $q->where('slug', $permission))->pluck('id')->all();
    }
}
