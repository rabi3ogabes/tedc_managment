<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainerAttendance;
use App\Models\User;
use Illuminate\Support\Str;

/** Other ways of taking attendance: tablet signature, staff scanning a personal QR, and the trainers' own attendance. */
class AttendanceMethodsService
{
    private const PREFIX = 'TEDC1P';

    public function __construct(private readonly AttendanceService $attendance, private readonly FileStorage $files) {}

    // Personal QR ---------------------------------------------------------------------------------------------

    /** The person's own QR (shown in their app or portal); it rotates every day. @return array{payload: string, expires_at: string} */
    public function personalQr(string $kind, string $id, ?string $day = null): array
    {
        $day ??= today()->toDateString();

        return ['payload' => implode('.', [self::PREFIX, $kind, $id, $day, $this->hmac($kind, $id, $day)]), 'expires_at' => now()->endOfDay()->toIso8601String()];
    }

    /** @return array{0: string, 1: string} kind (e|t) and id */
    public function resolvePersonal(string $payload): array
    {
        $p = explode('.', trim($payload));
        if (count($p) !== 5 || $p[0] !== self::PREFIX || ! in_array($p[1], ['e', 't'], true) || $p[3] !== today()->toDateString() || ! hash_equals($this->hmac($p[1], $p[2], $p[3]), $p[4])) {
            throw new BusinessRuleException(__('messages.attendance.invalid_qr'), 'invalid_qr');
        }

        return [$p[1], $p[2]];
    }

    // Kiosk ---------------------------------------------------------------------------------------------------

    public function roster(ProgramSession $session, User $opener): array
    {
        AuditLog::create(['user_id' => $opener->id, 'action' => 'kiosk_opened', 'auditable_type' => ProgramSession::class, 'auditable_id' => $session->id, 'new_values' => ['at' => now()->toIso8601String()]]);
        $records = $session->attendance()->get()->keyBy('registration_id');
        $regs = Registration::with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en'])->where('program_id', $session->program_id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->when($session->training_group_id && ! $session->program->hasSingleGroup(), fn ($q) => $q->where('training_group_id', $session->training_group_id))->get();

        return $regs->map(fn (Registration $r) => [
            'registration_id' => $r->id, 'employee_no' => $r->employee->employee_no, 'name' => $r->employee->user?->displayName(), 'school' => $r->employee->school?->translate('name'),
            'checked_in' => (bool) $records->get($r->id)?->check_in_at, 'checked_out' => (bool) $records->get($r->id)?->check_out_at, 'status' => $records->get($r->id)?->status,
        ])->sortBy('name')->values()->all();
    }

    /** A trainee signs on the tablet to enter or leave; the signature image is kept privately with the record. */
    public function sign(ProgramSession $session, Registration $registration, string $direction, string $dataUrl, User $actor): Attendance
    {
        abort_unless($registration->program_id === $session->program_id, 404);
        $png = $this->decodePng($dataUrl);
        $path = $this->files->put('submissions', 'signatures/'.$session->id.'/'.$registration->id.'-'.$direction.'-'.Str::uuid().'.png', $png, 'image/png');
        $now = now();
        $a = Attendance::firstOrNew(['program_session_id' => $session->id, 'registration_id' => $registration->id]);

        if ($direction === 'in') {
            $late = $now->gt($session->starts_at->copy()->addMinutes((int) config('tedc.attendance.late_after_minutes')));
            $a->fill(['employee_id' => $registration->employee_id, 'check_in_at' => $a->check_in_at ?? $now, 'status' => $a->status && $a->status !== 'absent' ? $a->status : ($late ? 'late' : 'present'), 'method' => 'signature', 'signature_path' => $path, 'recorded_by' => $actor->id, 'location_status' => 'manual'])->save();
        } else {
            if (! $a->exists || ! $a->check_in_at) {
                throw new BusinessRuleException(__('messages.attendance.not_checked_in'), 'not_checked_in');
            }
            $a->fill(['check_out_at' => $now, 'minutes_attended' => $this->overlap($session, $a->check_in_at, $now), 'signature_path' => $a->signature_path ?? $path, 'recorded_by' => $actor->id])->save();
        }
        $this->attendance->recalculate($registration);

        return $a->fresh();
    }

    // Staff scan ----------------------------------------------------------------------------------------------

    /** @return array{kind: string, action: string, name: ?string} */
    public function staffScan(ProgramSession $session, string $payload, User $staff): array
    {
        [$kind, $id] = $this->resolvePersonal($payload);

        if ($kind === 'e') {
            $employee = Employee::with('user')->findOrFail($id);
            $registration = Registration::where('program_id', $session->program_id)->where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->first();
            if (! $registration) {
                throw new BusinessRuleException(__('messages.attendance.not_registered'), 'not_registered');
            }
            $now = now();
            $a = Attendance::firstOrNew(['program_session_id' => $session->id, 'registration_id' => $registration->id]);
            if (! $a->exists || ! $a->check_in_at) {
                $late = $now->gt($session->starts_at->copy()->addMinutes((int) config('tedc.attendance.late_after_minutes')));
                $a->fill(['employee_id' => $employee->id, 'check_in_at' => $now, 'status' => $late ? 'late' : 'present', 'method' => 'staff_scan', 'recorded_by' => $staff->id, 'location_status' => 'manual'])->save();
                $action = 'check_in';
            } elseif (! $a->check_out_at) {
                $a->fill(['check_out_at' => $now, 'minutes_attended' => $this->overlap($session, $a->check_in_at, $now)])->save();
                $action = 'check_out';
            } else {
                throw new BusinessRuleException(__('messages.attendance.already_checked_out'), 'already_checked_out');
            }
            $this->attendance->recalculate($registration);

            return ['kind' => 'trainee', 'action' => $action, 'name' => $employee->user?->displayName()];
        }

        $trainer = Trainer::findOrFail($id);

        return ['kind' => 'trainer', 'action' => $this->toggleTrainer($session, $trainer, 'staff_scan', $staff->id), 'name' => $trainer->translate('name')];
    }

    // Trainer attendance --------------------------------------------------------------------------------------

    /** The trainer scans the session QR: first scan checks in, the second checks out. */
    public function trainerScan(User $user, string $payload): array
    {
        $session = $this->attendance->resolvePayload($payload);
        $trainer = $user->trainer;
        abort_unless($trainer && $session->trainer_id === $trainer->id, 403, __('messages.attendance.not_your_session'));

        return ['action' => $this->toggleTrainer($session, $trainer, 'qr', $user->id), 'session' => $session];
    }

    public function toggleTrainer(ProgramSession $session, Trainer $trainer, string $method, ?string $by): string
    {
        $row = TrainerAttendance::firstOrNew(['session_id' => $session->id, 'trainer_id' => $trainer->id]);
        if (! $row->exists || ! $row->check_in_at) {
            $row->fill(['check_in_at' => now(), 'method' => $method, 'recorded_by' => $by])->save();

            return 'check_in';
        }
        if ($row->check_out_at) {
            throw new BusinessRuleException(__('messages.attendance.already_checked_out'), 'already_checked_out');
        }
        $row->update(['check_out_at' => now()]);

        return 'check_out';
    }

    /** The supervisor enters the trainer's attendance: present (whole session unless times are given) or absent. */
    public function markTrainer(ProgramSession $session, Trainer $trainer, string $status, User $by, ?string $notes = null): TrainerAttendance
    {
        if ($status === 'absent') {
            return TrainerAttendance::updateOrCreate(['session_id' => $session->id, 'trainer_id' => $trainer->id], ['check_in_at' => null, 'check_out_at' => null, 'method' => 'manual', 'recorded_by' => $by->id, 'notes' => $notes]);
        }

        return TrainerAttendance::updateOrCreate(['session_id' => $session->id, 'trainer_id' => $trainer->id], ['check_in_at' => $session->starts_at, 'check_out_at' => $session->ends_at, 'method' => 'manual', 'recorded_by' => $by->id, 'notes' => $notes]);
    }

    /** @return list<array<string, mixed>> */
    public function trainerRows(ProgramSession $session): array
    {
        $rows = TrainerAttendance::with('trainer:id,name_ar,name_en')->where('session_id', $session->id)->get()->keyBy('trainer_id');
        $ids = collect([$session->trainer_id])->filter()->merge($rows->keys())->unique();

        return $ids->map(function ($tid) use ($rows, $session) {
            $r = $rows->get($tid);
            $trainer = $r?->trainer ?? Trainer::find($tid);

            return ['trainer_id' => $tid, 'name' => $trainer?->translate('name'), 'check_in_at' => $r?->check_in_at?->toIso8601String(), 'check_out_at' => $r?->check_out_at?->toIso8601String(), 'method' => $r?->method, 'minutes' => $r && $r->check_in_at ? $this->overlap($session, $r->check_in_at, $r->check_out_at ?? ($session->ends_at->isPast() ? $session->ends_at : now())) : 0];
        })->values()->all();
    }

    /** Teaching minutes a trainer actually attended (for their certificate and the hours report). */
    public function trainerMinutes(Trainer $trainer): int
    {
        $total = 0;
        foreach (TrainerAttendance::with('session')->where('trainer_id', $trainer->id)->whereNotNull('check_in_at')->get() as $r) {
            $total += $this->overlap($r->session, $r->check_in_at, $r->check_out_at ?? $r->session->ends_at);
        }

        return $total;
    }

    private function overlap(ProgramSession $session, $in, $out): int
    {
        $start = $in->max($session->starts_at);
        $end = $out->min($session->ends_at);

        return $end->gt($start) ? (int) $start->diffInMinutes($end) : 0;
    }

    private function decodePng(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new BusinessRuleException(__('messages.attendance.bad_signature'), 'bad_signature');
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) > 300_000 || ! str_starts_with($bin, "\x89PNG")) {
            throw new BusinessRuleException(__('messages.attendance.bad_signature'), 'bad_signature');
        }

        return $bin;
    }

    private function hmac(string $kind, string $id, string $day): string
    {
        return substr(hash_hmac('sha256', "P|{$kind}|{$id}|{$day}", (string) config('app.key')), 0, 20);
    }
}
