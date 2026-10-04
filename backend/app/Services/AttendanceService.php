<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\AttendanceAttempt;
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

    public function __construct(private readonly CertificateService $certificates, private readonly GeoFence $geofence) {}

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
     * Employee scans the QR code: first scan checks in, second scan checks out. Both must happen at the venue
     * (see GeoFence), so a QR code photographed and shared with someone elsewhere is of no use.
     *
     * @param  array{latitude?: mixed, longitude?: mixed, accuracy?: mixed, mocked?: mixed}|null  $location
     * @return array{action: string, attendance: Attendance, message: string}
     */
    public function scan(Employee $employee, string $payload, ?string $device = null, ?string $ip = null, ?array $location = null, bool $biometric = false, ?string $intent = null): array
    {
        try {
            $result = $this->performScan($employee, $payload, $device, $ip, $location, $biometric, $intent);
        } catch (BusinessRuleException $e) {
            $this->logAttempt($employee, $payload, 'rejected', $e->errorCode, $e->getMessage(), $device, $ip);

            throw $e;
        }
        if ($result['action'] === 'already_present') {
            $this->logAttempt($employee, $payload, 'already_present', 'already_present', $result['message'], $device, $ip);
        }

        return $result;
    }

    /** What the administrator sees under Attendance attempts; never allowed to break the scan itself. */
    private function logAttempt(Employee $employee, string $payload, string $outcome, string $code, string $message, ?string $device, ?string $ip): void
    {
        try {
            $session = null;
            $parts = explode('.', trim($payload));
            if (count($parts) === 4 && $parts[0] === self::PREFIX) {
                $session = ProgramSession::find($parts[1]);
            }
            AttendanceAttempt::create([
                'employee_id' => $employee->id, 'program_id' => $session?->program_id, 'program_session_id' => $session?->id,
                'outcome' => $outcome, 'code' => $code, 'message' => mb_substr($message, 0, 500), 'device_info' => $device ? substr($device, 0, 255) : null, 'ip_address' => $ip,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function performScan(Employee $employee, string $payload, ?string $device, ?string $ip, ?array $location, bool $biometric, ?string $intent): array
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

        // A program may ask for the phone's own unlock (fingerprint, face or passcode) before attendance counts.
        if ($session->program->require_biometric && ! $biometric) {
            throw new BusinessRuleException(__('messages.attendance.biometric_required'), 'biometric_required');
        }

        $attendance = Attendance::firstOrNew([
            'program_session_id' => $session->id,
            'registration_id' => $registration->id,
        ]);
        $isIn = $attendance->exists && $attendance->check_in_at;

        // Already present: scanning to enter again changes nothing. The participant is told, and can only leave (exit).
        if ($isIn && ! $attendance->check_out_at && $intent === 'check_in') {
            return ['action' => 'already_present', 'attendance' => $attendance, 'message' => __('messages.attendance.already_present')];
        }
        if (! $isIn && $intent === 'check_out') {
            throw new BusinessRuleException(__('messages.attendance.not_checked_in'), 'not_checked_in');
        }

        $place = $this->geofence->verify($session, $location);

        if (! $attendance->exists || ! $attendance->check_in_at) {
            $late = $now->gt($session->starts_at->copy()->addMinutes(config('tedc.attendance.late_after_minutes')));
            $attendance->fill([
                'employee_id' => $employee->id,
                'check_in_at' => $now,
                'method' => 'qr',
                'biometric_verified' => $biometric,
                'status' => $late ? 'late' : 'present',
                'device_info' => $device ? substr($device, 0, 255) : null,
                'ip_address' => $ip,
                'latitude' => $place['latitude'],
                'longitude' => $place['longitude'],
                'accuracy_m' => $place['accuracy_m'],
                'distance_m' => $place['distance_m'],
                'location_status' => $place['status'],
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

    // Remote sessions --------------------------------------------------------------------------------------------

    public function joinOpensAt(ProgramSession $session): Carbon
    {
        $minutes = (int) ($session->program->remote['join_opens_minutes'] ?? 15);

        return $session->starts_at->copy()->subMinutes($minutes);
    }

    /**
     * A trainee joins an online session: the join is the attendance. Joining opens `join_opens_minutes` before
     * the start and stays possible until the session ends; a join after the grace period counts as late.
     * The meeting link and passcode are only handed out here, inside the window.
     *
     * @return array{attendance: Attendance, join_url: ?string, passcode: ?string, platform: ?string, message: string}
     */
    public function remoteJoin(Employee $employee, ProgramSession $session, ?string $device = null, ?string $ip = null): array
    {
        $session->loadMissing('program');
        if ($session->mode !== 'online' || $session->status === 'cancelled') {
            throw new BusinessRuleException(__('messages.attendance.not_online'), 'not_online');
        }
        $now = now();
        if ($now->lt($this->joinOpensAt($session))) {
            throw new BusinessRuleException(__('messages.attendance.not_open'), 'not_open');
        }
        if ($now->gt($session->ends_at)) {
            throw new BusinessRuleException(__('messages.attendance.closed'), 'closed');
        }

        $registration = Registration::where('program_id', $session->program_id)->where('employee_id', $employee->id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->first();
        if (! $registration) {
            throw new BusinessRuleException(__('messages.attendance.not_registered'), 'not_registered');
        }

        $attendance = Attendance::firstOrNew(['program_session_id' => $session->id, 'registration_id' => $registration->id]);
        if (! $attendance->exists || ! $attendance->check_in_at) {
            $late = $now->gt($session->starts_at->copy()->addMinutes(config('tedc.attendance.late_after_minutes')));
            $attendance->fill([
                'employee_id' => $employee->id, 'check_in_at' => $now, 'method' => 'remote', 'status' => $late ? 'late' : 'present',
                'device_info' => $device ? substr($device, 0, 255) : null, 'ip_address' => $ip, 'location_status' => 'remote',
            ]);
        }
        // Coming back after leaving: the participant is attending again.
        $attendance->fill(['check_out_at' => null, 'join_count' => ($attendance->join_count ?? 0) + 1, 'last_join_at' => $now])->save();

        $this->recalculate($registration);

        return [
            'attendance' => $attendance->fresh(), 'join_url' => $session->online_url, 'passcode' => $session->online_passcode,
            'platform' => $session->online_platform, 'message' => __('messages.attendance.joined'),
        ];
    }

    /** The trainee leaves the online session: the minutes attended are fixed at this moment. */
    public function remoteLeave(Employee $employee, ProgramSession $session): Attendance
    {
        $attendance = Attendance::where('program_session_id', $session->id)->where('employee_id', $employee->id)->whereNotNull('check_in_at')->first();
        if (! $attendance) {
            throw new BusinessRuleException(__('messages.attendance.not_registered'), 'not_joined');
        }
        $attendance->update(['check_out_at' => now(), 'minutes_attended' => $this->overlapMinutes($session, $attendance->check_in_at, now())]);
        $this->recalculate($attendance->registration);

        return $attendance->fresh();
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
                'location_status' => 'manual',
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
            'location_status' => $records->get($r->id)?->location_status,
            'distance_m' => $records->get($r->id)?->distance_m,
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
