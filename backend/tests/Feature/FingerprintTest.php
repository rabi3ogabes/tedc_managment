<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TrainingRoom;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FingerprintTest extends TestCase
{
    private function room(): TrainingRoom
    {
        return TrainingRoom::create(['code' => 'R-'.uniqid(), 'name_ar' => 'قاعة', 'name_en' => 'Room', 'capacity' => 30, 'status' => 'active']);
    }

    /** @return array{0: AttendanceDevice, 1: Registration, 2: ProgramSession, 3: Employee} */
    private function world(string $vendor = 'generic_http'): array
    {
        $room = $this->room();
        $device = AttendanceDevice::create(['name' => 'Gate 1', 'vendor' => $vendor, 'serial' => 'SN1', 'location_room_id' => $room->id, 'api_config' => ['secret' => 'topsecret']]);
        $program = $this->makeProgram();
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $session = $this->makeSession($program, now()->subMinutes(10));
        $session->update(['training_room_id' => $room->id]);

        return [$device, $registration, $session, $employee];
    }

    private function sign(array $body, string $secret = 'topsecret'): array
    {
        return ['X-Signature' => hash_hmac('sha256', json_encode($body), $secret), 'Content-Type' => 'application/json'];
    }

    public function test_a_signed_webhook_creates_attendance_in_the_devices_room_and_ignores_duplicates(): void
    {
        [$device, $registration, $session, $employee] = $this->world();
        $at = now()->subMinutes(5);
        $body = ['punches' => [['person_ref' => $employee->employee_no, 'punched_at' => $at->toIso8601String(), 'direction' => 'in']]];

        $this->postJson("/api/v1/integrations/fingerprint/{$device->id}/punches", $body)->assertStatus(401);
        $res = $this->call('POST', "/api/v1/integrations/fingerprint/{$device->id}/punches", [], [], [], $this->transformHeadersToServerVars($this->sign($body)), json_encode($body))->assertOk();
        $this->assertSame(1, $res->json('data.matched'));

        $a = Attendance::where('registration_id', $registration->id)->first();
        $this->assertSame('fingerprint', $a->method);
        $this->assertSame($device->id, $a->device_id);
        $this->assertSame($session->id, $a->program_session_id);

        $this->call('POST', "/api/v1/integrations/fingerprint/{$device->id}/punches", [], [], [], $this->transformHeadersToServerVars($this->sign($body)), json_encode($body))->assertOk()->assertJsonPath('data.duplicates', 1);
        $this->assertSame(1, DevicePunch::count());
    }

    public function test_a_later_punch_checks_out_and_unmatched_punches_are_reported(): void
    {
        [$device, $registration, , $employee] = $this->world();
        $in = now()->subMinutes(8);
        $out = now()->subMinutes(1);
        $body = ['punches' => [
            ['person_ref' => $employee->employee_no, 'punched_at' => $in->toIso8601String()],
            ['person_ref' => $employee->employee_no, 'punched_at' => $out->toIso8601String()],
            ['person_ref' => 'NOBODY', 'punched_at' => $out->toIso8601String()],
            ['person_ref' => $employee->employee_no, 'punched_at' => now()->subDays(3)->toIso8601String()],
        ]];

        $res = $this->call('POST', "/api/v1/integrations/fingerprint/{$device->id}/punches", [], [], [], $this->transformHeadersToServerVars($this->sign($body)), json_encode($body))->assertOk();

        $this->assertSame(2, $res->json('data.matched'));
        $this->assertCount(2, $res->json('data.unmatched'));
        $a = Attendance::where('registration_id', $registration->id)->first();
        $this->assertNotNull($a->check_out_at);
        $this->assertGreaterThan(0, $a->minutes_attended);
    }

    public function test_the_csv_driver_imports_a_file_and_the_zkteco_push_format_is_understood(): void
    {
        [$device, $registration, , $employee] = $this->world('csv');
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $csv = "person_ref,punched_at,direction\n{$employee->employee_no},".now()->subMinutes(6)->format('Y-m-d H:i:s').",in\n";

        $res = $this->asUser($admin)->post("/api/v1/admin/attendance-devices/{$device->id}/import", ['file' => UploadedFile::fake()->createWithContent('punches.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, $res->json('data.matched'));
        $this->assertNotNull(AttendanceDevice::find($device->id)->last_sync_at);

        [$zk, $reg2, , $emp2] = $this->world('zkteco');
        $line = "{$emp2->employee_no}\t".now()->subMinutes(4)->format('Y-m-d H:i:s')."\t0\t1\t0\t0\n";
        $this->call('POST', "/api/v1/integrations/fingerprint/{$zk->id}/punches?SN=SN1&table=ATTLOG", [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $line, 'topsecret')], $line)->assertOk();
        $this->assertSame('fingerprint', Attendance::where('registration_id', $reg2->id)->value('method'));
        $this->assertNotNull($registration);
    }

    public function test_devices_are_managed_only_by_those_who_may_and_the_secret_is_never_returned(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $room = $this->room();

        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/attendance-devices', ['name' => 'X', 'vendor' => 'csv'])->assertForbidden();
        $res = $this->asUser($admin)->postJson('/api/v1/admin/attendance-devices', ['name' => 'Hall', 'vendor' => 'generic_http', 'serial' => 'S9', 'location_room_id' => $room->id, 'secret' => 'abc123'])->assertCreated();
        $this->assertArrayNotHasKey('api_config', $res->json('data'));
        $this->assertTrue($res->json('data.has_secret'));
        $this->asUser($admin)->postJson('/api/v1/admin/attendance-devices/'.$res->json('data.id').'/test')->assertOk()->assertJsonPath('data.ok', true);
    }
}
