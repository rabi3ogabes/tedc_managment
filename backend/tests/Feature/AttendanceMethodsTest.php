<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainerAttendance;
use App\Services\AttendanceService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceMethodsTest extends TestCase
{
    private function setupSession(int $startsMinutesAgo = 5): array
    {
        $program = $this->makeProgram();
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $session = $this->makeSession($program, now()->subMinutes($startsMinutesAgo));

        return [$program, $employee, $registration, $session];
    }

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_qr_check_in_is_refused_outside_the_sessions_window_and_the_attempt_is_logged(): void
    {
        [, $employee, , $session] = $this->setupSession(40);
        $session->update(['checkin_window_minutes' => 20]);
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];

        $this->asUser($employee->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload])->assertStatus(422)->assertJsonPath('code', 'checkin_window_closed');
        $this->assertDatabaseHas('attendance_attempts', ['code' => 'checkin_window_closed']);
        $this->assertSame(0, Attendance::count());
    }

    public function test_qr_check_out_is_only_accepted_near_the_end_when_a_window_is_set(): void
    {
        [, $employee, $registration, $session] = $this->setupSession(5);
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];
        $this->asUser($employee->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload])->assertOk();
        $session->update(['checkout_window_minutes' => 10]);

        $this->travel(30)->minutes();
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];
        $this->asUser($employee->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload, 'intent' => 'check_out'])->assertStatus(422)->assertJsonPath('code', 'checkout_window_closed');

        $this->travel($session->durationMinutes() - 30 - 5 - 5)->minutes();
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];
        $this->asUser($employee->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload, 'intent' => 'check_out'])->assertOk()->assertJsonPath('data.action', 'check_out');
        $this->assertNotNull($registration);
    }

    public function test_the_kiosk_lists_the_roster_audits_who_opened_it_and_stores_the_signature(): void
    {
        Storage::fake('local');
        [, $employee, $registration, $session] = $this->setupSession();
        $supervisor = $this->makeUser(Role::COORDINATOR);

        $roster = $this->asUser($supervisor)->getJson("/api/v1/admin/sessions/{$session->id}/kiosk")->assertOk()->json('data.roster');
        $this->assertCount(1, $roster);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kiosk_opened', 'user_id' => $supervisor->id]);

        $this->asUser($supervisor)->postJson("/api/v1/admin/sessions/{$session->id}/signatures", ['registration_id' => $registration->id, 'direction' => 'in', 'signature' => self::PNG])->assertOk()->assertJsonPath('data.method', 'signature');
        $a = Attendance::where('registration_id', $registration->id)->first();
        $this->assertSame('signature', $a->method);
        $this->assertNotNull($a->signature_path);
        $this->assertNotNull($a->check_in_at);

        $this->travel(90)->minutes();
        $this->asUser($supervisor)->postJson("/api/v1/admin/sessions/{$session->id}/signatures", ['registration_id' => $registration->id, 'direction' => 'out', 'signature' => self::PNG])->assertOk();
        $this->assertNotNull($a->fresh()->check_out_at);
        $this->assertGreaterThan(0, $a->fresh()->minutes_attended);

        $this->asUser($supervisor)->postJson("/api/v1/admin/sessions/{$session->id}/signatures", ['registration_id' => $registration->id, 'direction' => 'in', 'signature' => 'not-an-image'])->assertStatus(422);
        $this->assertNotNull($employee);
    }

    public function test_manual_entry_by_a_trainer_is_limited_to_the_configured_window_but_centre_staff_are_not(): void
    {
        [, , $registration, $session] = $this->setupSession(45);
        $trainer = $this->makeUser(Role::TRAINER);
        $session->update(['trainer_id' => Trainer::create(['user_id' => $trainer->id, 'name_ar' => 'م', 'name_en' => 'T', 'status' => 'active', 'source' => 'center'])->id]);
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/attendance', ['manual_window_minutes' => 20])->assertOk();

        $this->asUser($trainer)->postJson("/api/v1/admin/sessions/{$session->id}/attendance", ['registration_id' => $registration->id, 'status' => 'present'])->assertStatus(422)->assertJsonPath('code', 'manual_window_closed');
        $this->asUser($admin)->postJson("/api/v1/admin/sessions/{$session->id}/attendance", ['registration_id' => $registration->id, 'status' => 'present'])->assertOk();
    }

    public function test_staff_scan_the_personal_qr_of_a_trainee_and_a_trainer_but_need_the_grant(): void
    {
        [, $employee, $registration, $session] = $this->setupSession();
        $trainerUser = $this->makeUser(Role::TRAINER);
        $trainer = Trainer::create(['user_id' => $trainerUser->id, 'name_ar' => 'م', 'name_en' => 'T', 'status' => 'active', 'source' => 'center']);
        $session->update(['trainer_id' => $trainer->id]);
        $staff = $this->makeUser(Role::COORDINATOR);

        $traineeQr = $this->asUser($employee->user)->getJson('/api/v1/me/attendance-qr')->assertOk()->json('data.payload');
        $trainerQr = $this->asUser($trainerUser)->getJson('/api/v1/me/attendance-qr')->assertOk()->json('data.payload');

        $this->asUser($employee->user)->postJson("/api/v1/admin/sessions/{$session->id}/staff-scan", ['payload' => $traineeQr])->assertForbidden();
        $this->asUser($staff)->postJson("/api/v1/admin/sessions/{$session->id}/staff-scan", ['payload' => 'garbage'])->assertStatus(422)->assertJsonPath('code', 'invalid_qr');

        $this->asUser($staff)->postJson("/api/v1/admin/sessions/{$session->id}/staff-scan", ['payload' => $traineeQr])->assertOk()->assertJsonPath('data.kind', 'trainee');
        $this->assertSame('staff_scan', Attendance::where('registration_id', $registration->id)->value('method'));

        $this->asUser($staff)->postJson("/api/v1/admin/sessions/{$session->id}/staff-scan", ['payload' => $trainerQr])->assertOk()->assertJsonPath('data.kind', 'trainer');
        $this->assertSame('staff_scan', TrainerAttendance::where('trainer_id', $trainer->id)->value('method'));
    }

    public function test_trainers_check_in_by_the_session_qr_or_are_marked_by_the_supervisor_and_hours_are_counted(): void
    {
        [, , , $session] = $this->setupSession(5);
        $trainerUser = $this->makeUser(Role::TRAINER);
        $trainer = Trainer::create(['user_id' => $trainerUser->id, 'name_ar' => 'م', 'name_en' => 'T', 'status' => 'active', 'source' => 'center']);
        $other = $this->makeUser(Role::TRAINER);
        $session->update(['trainer_id' => $trainer->id]);
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];

        $this->asUser($other)->postJson('/api/v1/me/trainer-attendance/scan', ['payload' => $payload])->assertStatus(403);
        $this->asUser($trainerUser)->postJson('/api/v1/me/trainer-attendance/scan', ['payload' => $payload])->assertOk()->assertJsonPath('data.action', 'check_in');
        $this->travel(60)->minutes();
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];
        $this->asUser($trainerUser)->postJson('/api/v1/me/trainer-attendance/scan', ['payload' => $payload])->assertOk()->assertJsonPath('data.action', 'check_out');

        $row = TrainerAttendance::where('trainer_id', $trainer->id)->first();
        $this->assertSame('qr', $row->method);
        $list = $this->asUser($this->makeUser(Role::COORDINATOR))->getJson("/api/v1/admin/sessions/{$session->id}/trainer-attendance")->assertOk()->json('data');
        $this->assertSame($trainer->id, $list[0]['trainer_id']);
        $this->assertGreaterThanOrEqual(55, $list[0]['minutes']);

        $second = $this->makeSession($session->program, now()->addDay());
        $second->update(['trainer_id' => $trainer->id]);
        $this->asUser($this->makeUser(Role::COORDINATOR))->postJson("/api/v1/admin/sessions/{$second->id}/trainer-attendance", ['trainer_id' => $trainer->id, 'status' => 'present'])->assertOk();
        $this->assertSame(2, TrainerAttendance::where('trainer_id', $trainer->id)->count());
    }
}
