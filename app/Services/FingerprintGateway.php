<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Trainer;
use Carbon\Carbon;

/**
 * Punches from fingerprint / badge devices become attendance. Drivers: a signed HTTP webhook (generic or ZKTeco ADMS push)
 * and a CSV import (also used in development). A punch is matched to the person by employee number and to the session
 * running in the device's room around that time; unmatched punches are kept and reported.
 */
class FingerprintGateway
{
    private const MIN_GAP_MINUTES = 5;

    public function __construct(private readonly AttendanceService $attendance, private readonly AttendanceMethodsService $methods) {}

    /** The device's shared secret decides whether a pushed body is accepted. */
    public function verifySignature(AttendanceDevice $device, string $body, ?string $signature): bool
    {
        $secret = $device->api_config['secret'] ?? null;

        return $secret && $signature && hash_equals(hash_hmac('sha256', $body, $secret), strtolower($signature));
    }

    /** Parses a webhook body: JSON `{punches: [...]}` or ZKTeco ATTLOG lines (`PIN<TAB>time<TAB>status…`). @return list<array{person_ref: string, punched_at: Carbon, direction: string, raw: array}> */
    public function parse(string $body, ?string $contentType): array
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return collect($json['punches'] ?? $json)->map(fn ($p) => $this->punch($p['person_ref'] ?? $p['pin'] ?? '', $p['punched_at'] ?? $p['time'] ?? '', $p['direction'] ?? 'unknown', $p))->filter()->values()->all();
        }
        $out = [];
        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            $cols = preg_split('/\t+/', trim($line));
            if (count($cols) >= 2 && ($p = $this->punch($cols[0], $cols[1], isset($cols[2]) ? (['0' => 'in', '1' => 'out'][$cols[2]] ?? 'unknown') : 'unknown', ['line' => $line]))) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** @return list<array{person_ref: string, punched_at: Carbon, direction: string, raw: array}> */
    public function parseCsv(string $contents): array
    {
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($contents)));
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $out = [];
        foreach ($rows as $r) {
            $row = array_combine($header, array_pad($r, count($header), null));
            if ($p = $this->punch((string) ($row['person_ref'] ?? ''), (string) ($row['punched_at'] ?? ''), (string) ($row['direction'] ?? 'unknown'), $row)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{person_ref: string, punched_at: Carbon, direction: string, raw: array}>  $punches
     * @return array{received: int, matched: int, duplicates: int, unmatched: list<array{person_ref: string, punched_at: string, reason: string}>}
     */
    public function ingest(AttendanceDevice $device, array $punches): array
    {
        $summary = ['received' => count($punches), 'matched' => 0, 'duplicates' => 0, 'unmatched' => []];
        usort($punches, fn ($a, $b) => $a['punched_at'] <=> $b['punched_at']);

        foreach ($punches as $p) {
            if (DevicePunch::where('device_id', $device->id)->where('person_ref', $p['person_ref'])->where('punched_at', $p['punched_at'])->exists()) {
                $summary['duplicates']++;

                continue;
            }
            $row = DevicePunch::create(['device_id' => $device->id, 'person_ref' => $p['person_ref'], 'punched_at' => $p['punched_at'], 'direction' => $p['direction'], 'raw' => $p['raw']]);
            [$outcome, $attendanceId] = $this->match($device, $p);
            $row->update(['outcome' => $outcome, 'attendance_id' => $attendanceId, 'processed_at' => now()]);
            if (in_array($outcome, ['matched', 'duplicate'], true)) {
                $outcome === 'matched' ? $summary['matched']++ : $summary['duplicates']++;
            } else {
                $summary['unmatched'][] = ['person_ref' => $p['person_ref'], 'punched_at' => $p['punched_at']->toIso8601String(), 'reason' => $outcome];
            }
        }
        $device->update(['last_sync_at' => now(), 'last_error' => null]);

        return $summary;
    }

    /** @return array{0: string, 1: ?string} outcome and attendance id */
    private function match(AttendanceDevice $device, array $p): array
    {
        $at = $p['punched_at'];
        $employee = Employee::where('employee_no', $p['person_ref'])->first();
        $trainer = $employee ? Trainer::where('employee_id', $employee->id)->first() : null;
        if (! $employee && ! $trainer) {
            return ['unmatched_person', null];
        }
        $session = ProgramSession::where('training_room_id', $device->location_room_id)->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', $at->copy()->addMinutes(30))->where('ends_at', '>=', $at->copy()->subMinutes(30))->orderBy('starts_at')->first();
        if (! $session) {
            return ['unmatched_session', null];
        }

        $registration = $employee ? Registration::where('program_id', $session->program_id)->where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->first() : null;
        if (! $registration) {
            if ($trainer && $session->trainer_id === $trainer->id) {
                $this->methods->toggleTrainer($session, $trainer, 'fingerprint', null);

                return ['matched', null];
            }

            return ['unmatched_person', null];
        }

        $a = Attendance::firstOrNew(['program_session_id' => $session->id, 'registration_id' => $registration->id]);
        if (! $a->exists || ! $a->check_in_at) {
            $late = $at->gt($session->starts_at->copy()->addMinutes((int) config('tedc.attendance.late_after_minutes')));
            $a->fill(['employee_id' => $employee->id, 'check_in_at' => $at, 'status' => $late ? 'late' : 'present', 'method' => 'fingerprint', 'device_id' => $device->id, 'location_status' => 'device'])->save();
        } elseif (! $a->check_out_at && $at->diffInMinutes($a->check_in_at, true) >= self::MIN_GAP_MINUTES) {
            $a->fill(['check_out_at' => $at, 'minutes_attended' => (int) max(0, $a->check_in_at->max($session->starts_at)->diffInMinutes($at->min($session->ends_at), false))])->save();
        } else {
            return ['duplicate', $a->id];
        }
        $this->attendance->recalculate($registration);

        return ['matched', $a->id];
    }

    private function punch(string $ref, string $time, string $direction, array $raw): ?array
    {
        $ref = trim($ref);
        $ts = strtotime($time);
        if ($ref === '' || $ts === false) {
            return null;
        }

        return ['person_ref' => $ref, 'punched_at' => Carbon::createFromTimestamp($ts, config('app.timezone')), 'direction' => in_array($direction, ['in', 'out'], true) ? $direction : 'unknown', 'raw' => $raw];
    }
}
