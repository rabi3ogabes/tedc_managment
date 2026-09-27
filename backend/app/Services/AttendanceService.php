<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Dynamic QR attendance.
 *
 * The trainer screen shows a QR code that rotates every `qr_rotation_seconds`.
 * Payload format: TEDC1.{session_id}.{window}.{signature}
 * where signature = HMAC-SHA256(session.qr_secret, "{session_id}.{window}") truncated to 20 hex chars.
 * A screenshot therefore stops working after at most two rotation windows.
 */
class AttendanceService
{
    private const PREFIX = 'TEDC1';

    public function __construct(private readonly CertificateService $certificates) {}

    /** @return array{payload: string, expires_at: string, rotation_seconds: int} */
    public function currentQr(ProgramSession $session, ?int $timestamp = null): array
    {
        $rotation = $this->rotation();
        $timestamp ??= time();
        $window = intdiv($timestamp, $rotation);

        return [
            'payload' => $this->payload($session, $window),
            'expires_at' => Carbon::createFromTimestamp(($window + 1) * $rotation)->toIso8601String(),
            'rotation_seconds' => $rotation,
        ];
    }

    public function resolvePayload(string $payload, ?int $timestamp = null): ProgramSession
    {
        $parts = explode('.', trim($payload));
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            throw new BusinessRuleException(__('messages.attendance.invalid_qr'), 'invalid_qr');
        }

        [, $sessionId, $window, $signature] = $parts;
        $session = ProgramSession::find($sessionId);
        $current = intdiv($timestamp ?? time(), $this->rotation());

        $valid = $session
            && ctype_digit($window)
            && in_array((int) $window, [$current, $current - 1], true)
            && hash_equals($this->sign($session, (int) $window), $signature);

        if (! $valid) {
            throw new BusinessRuleException(__('messages.attendance.invalid_qr'), 'invalid_qr');
        }

        return $session;
    }

    /**
     * Employee scans the QR code: first scan checks in, second scan checks out.
     *
     * @return array{action: string, attendance: Attendance, message: string}
     */
    public function scan(Employee $employee, string $payload, ?string $device = null, ?string $ip = null): array
    {
        $session = $this->resolvePayload($payload);
        $now = now();

        if ($now->lt($session->starts_at->copy()->subMinutes(config('tedc.attendance.check_in_opens_minutes_before')))) {
            throw new BusinessRuleException(__('messages.attendance.not_open'), 'not_open');
        }
        if ($now->gt($session->ends_at->copy()->addMinutes(30))) {
            throw new BusinessRuleException(__('messages.attendance.closed'), 'closed');
        }

        $registration = Registration::where('program_id', $session->program_id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->first();

        if (! $registration) {
            throw new BusinessRuleException(__('messages.attendance.not_registered'), 'not_registered');
        }

        $attendance = Attendance::firstOrNew([
            'program_session_id' => $session->id,
            'registration_id' => $registration->id,
        ]);

        if (! $attendance->exists || ! $attendance->check_in_at) {
            $late = $now->gt($session->starts_at->copy()->addMinutes(config('tedc.attendance.late_after_minutes')));
            $attendance->fill([
                'employee_id' => $employee->id,
                'check_in_at' => $now,
                'method' => 'qr',
                'status' => $late ? 'late' : 'present',
                'device_info' => $device ? substr($device, 0, 255) : null,
                'ip_address' => $ip,
            ])->save();
            $action = 'check_in';
        } elseif (! $attendance->check_out_at) {
            $attendance->check_out_at = $now;
            $attendance->minutes_attended = $this->overlapMinutes($session, $attendance->check_in_at, $now);
            $attendance->save();
            $action = 'check_out';
        } else {
            throw new BusinessRuleException(__('messages.attendance.already_checked_out'), 'already_checked_out');
        }

        $this->recalculate($registration);

        return [
            'action' => $action,
            'attendance' => $attendance->fresh(),
            'message' => __($action === 'check_in' ? 'messages.attendance.checked_in' : 'messages.attendance.checked_out'),
        ];
    }

    /**
     * Manual marking by a trainer / coordinator (e.g. device issues, excused absence).
     */
    public function mark(ProgramSession $session, Registration $registration, string $status, User $actor, ?int $minutes = null): Attendance
    {
        $full = $session->durationMinutes();
        $minutes ??= match ($status) {
            'present', 'excused' => $full,
            'late' => (int) round($full * 0.75),
            default => 0,
        };

        $attendance = Attendance::updateOrCreate(
            ['program_session_id' => $session->id, 'registration_id' => $registration->id],
            [
                'employee_id' => $registration->employee_id,
                'status' => $status,
                'method' => 'manual',
                'minutes_attended' => min($minutes, $full),
                'check_in_at' => in_array($status, ['present', 'late'], true) ? $session->starts_at : null,
                'check_out_at' => in_array($status, ['present', 'late'], true) ? $session->ends_at : null,
                'recorded_by' => $actor->id,
            ],
        );

        $this->recalculate($registration);

        return $attendance;
    }

    /**
     * Attendance % = attended minutes / scheduled minutes across all non-cancelled sessions.
     * Excused sessions are removed from the denominator. Open check-ins (no check-out) of
     * finished sessions are credited until the session end.
     */
    public function recalculate(Registration $registration): float
    {
        $sessions = ProgramSession::where('program_id', $registration->program_id)->where('status', '!=', 'cancelled')->get();
        $records = Attendance::where('registration_id', $registration->id)->get()->keyBy('program_session_id');

        $scheduled = 0;
        $attended = 0;

        foreach ($sessions as $session) {
            $record = $records->get($session->id);
            if ($record?->status === 'excused') {
                continue;
            }

            $duration = $session->durationMinutes();
            $scheduled += $duration;

            if (! $record || $record->status === 'absent') {
                continue;
            }

            $minutes = $record->minutes_attended;
            if (! $record->check_out_at && $record->check_in_at && $session->ends_at->isPast()) {
                $minutes = $this->overlapMinutes($session, $record->check_in_at, $session->ends_at);
            }
            $attended += min($minutes, $duration);
        }

        $percent = $scheduled > 0 ? round($attended / $scheduled * 100, 2) : 0.0;
        $registration->update(['attendance_percent' => $percent]);
        $this->certificates->refreshStatus($registration);

        return $percent;
    }

    public function sessionReport(ProgramSession $session): array
    {
        $registrations = Registration::with('employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en')
            ->where('program_id', $session->program_id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->get();
        $records = $session->attendance()->get()->keyBy('registration_id');

        $rows = $registrations->map(fn (Registration $r) => [
            'registration_id' => $r->id,
            'employee_id' => $r->employee_id,
            'employee_no' => $r->employee->employee_no,
            'name' => $r->employee->user->displayName(),
            'school' => $r->employee->school?->translate('name'),
            'status' => $records->get($r->id)?->status ?? 'absent',
            'check_in_at' => $records->get($r->id)?->check_in_at?->toIso8601String(),
            'check_out_at' => $records->get($r->id)?->check_out_at?->toIso8601String(),
            'minutes' => $records->get($r->id)?->minutes_attended ?? 0,
        ])->values();

        return [
            'expected' => $rows->count(),
            'present' => $rows->whereIn('status', ['present', 'late'])->count(),
            'late' => $rows->where('status', 'late')->count(),
            'absent' => $rows->where('status', 'absent')->count(),
            'rows' => $rows,
        ];
    }

    private function overlapMinutes(ProgramSession $session, Carbon $in, Carbon $out): int
    {
        $start = $in->max($session->starts_at);
        $end = $out->min($session->ends_at);

        return $end->gt($start) ? (int) $start->diffInMinutes($end) : 0;
    }

    private function payload(ProgramSession $session, int $window): string
    {
        return implode('.', [self::PREFIX, $session->id, $window, $this->sign($session, $window)]);
    }

    private function sign(ProgramSession $session, int $window): string
    {
        return substr(hash_hmac('sha256', "{$session->id}.{$window}", $session->qr_secret), 0, 20);
    }

    private function rotation(): int
    {
        return max(10, (int) config('tedc.attendance.qr_rotation_seconds'));
    }
}
