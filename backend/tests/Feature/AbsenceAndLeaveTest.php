<?php

namespace Tests\Feature;

use App\Models\AbsenceAlert;
use App\Models\AbsenceExcuse;
use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AbsenceAndLeaveTest extends TestCase
{
    /** @return array{0: Program, 1: Registration, 2: list<ProgramSession>, 3: User, 4: User} program, registration, sessions, coordinator, manager user */
    private function world(int $sessions = 10): array
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $managerUser = $this->makeUser(Role::SUPERVISOR);
        $manager = $this->makeEmployee([], $managerUser);
        $program = $this->makeProgram(['min_attendance_percent' => 80, 'coordinator_id' => $coordinator->id]);
        $employee = $this->makeEmployee(['supervisor_id' => $manager->id]);
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED, 'manager_id' => $managerUser->id]);
        $list = [];
        foreach (range(1, $sessions) as $i) {
            $list[] = $this->makeSession($program, now()->subDays($sessions - $i + 1), 2);
        }

        return [$program, $registration, $list, $coordinator, $managerUser];
    }

    private function present(Registration $r, array $sessions, array $except): void
    {
        foreach ($sessions as $i => $s) {
            Attendance::create(['program_session_id' => $s->id, 'registration_id' => $r->id, 'employee_id' => $r->employee_id, 'status' => in_array($i, $except, true) ? 'absent' : 'present', 'method' => 'manual', 'minutes_attended' => in_array($i, $except, true) ? 0 : 120, 'check_in_at' => $s->starts_at, 'check_out_at' => $s->ends_at]);
        }
    }

    public function test_a_warning_then_a_breach_are_announced_once_each_to_the_right_people(): void
    {
        [, $r, $sessions, $coordinator, $managerUser] = $this->world();
        $this->present($r, $sessions, [0]);

        $this->artisan('tedc:operations-hourly')->assertSuccessful();
        $this->artisan('tedc:operations-hourly')->assertSuccessful();
        $this->assertSame(1, AbsenceAlert::where('registration_id', $r->id)->where('level', 'warning')->count());
        $this->assertSame(1, AppNotification::where('user_id', $coordinator->id)->where('type', 'attendance.absence_warning')->count());
        $this->assertSame(1, AppNotification::where('user_id', $r->employee->user_id)->where('type', 'attendance.absence_warning')->count());
        $this->assertSame(0, AppNotification::where('user_id', $managerUser->id)->where('type', 'attendance.absence_breach')->count());

        Attendance::where('registration_id', $r->id)->whereIn('program_session_id', [$sessions[1]->id, $sessions[2]->id])->update(['status' => 'absent', 'minutes_attended' => 0]);
        $this->artisan('tedc:operations-hourly')->assertSuccessful();
        $this->artisan('tedc:operations-hourly')->assertSuccessful();

        $alert = AbsenceAlert::where('registration_id', $r->id)->where('level', 'breach')->first();
        $this->assertNotNull($alert);
        $this->assertEquals(30, $alert->absence_percent);
        foreach ([$coordinator->id, $r->employee->user_id, $managerUser->id] as $uid) {
            $this->assertSame(1, AppNotification::where('user_id', $uid)->where('type', 'attendance.absence_breach')->count());
        }
    }

    public function test_the_supervisor_can_add_a_note_and_resend(): void
    {
        [, $r, $sessions, $coordinator] = $this->world();
        $this->present($r, $sessions, [0, 1, 2]);
        $this->artisan('tedc:operations-hourly')->assertSuccessful();
        $alert = AbsenceAlert::where('registration_id', $r->id)->where('level', 'breach')->first();

        $this->asUser($coordinator)->getJson('/api/v1/admin/absence-alerts')->assertOk()->assertJsonCount(2, 'data');
        $this->asUser($coordinator)->putJson("/api/v1/admin/absence-alerts/{$alert->id}", ['supervisor_note' => 'Please call me', 'resend' => true])->assertOk();
        $this->assertSame('Please call me', $alert->fresh()->supervisor_note);
        $this->assertSame(2, AppNotification::where('user_id', $r->employee->user_id)->where('type', 'attendance.absence_breach')->count());
    }

    public function test_an_approved_excuse_marks_the_day_excused_and_stops_it_counting_against_the_trainee(): void
    {
        [, $r, $sessions, $coordinator, $managerUser] = $this->world();
        $this->present($r, $sessions, [0]);
        $trainee = $r->employee->user;

        $res = $this->asUser($trainee)->post("/api/v1/me/registrations/{$r->id}/excuses", ['session_id' => $sessions[0]->id, 'reason_code' => 'sick_leave', 'reason_text' => 'Flu', 'attachments' => [UploadedFile::fake()->create('note.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $id = $res->json('data.id');
        $this->assertTrue(AppNotification::where('user_id', $managerUser->id)->where('type', 'excuse.submitted')->exists());
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson("/api/v1/admin/excuses/{$id}/decision", ['decision' => 'approved'])->assertForbidden();
        $this->asUser($managerUser)->getJson('/api/v1/admin/excuses?status=pending')->assertOk()->assertJsonCount(1, 'data');

        $this->asUser($managerUser)->postJson("/api/v1/admin/excuses/{$id}/decision", ['decision' => 'rejected'])->assertStatus(422);
        $this->asUser($managerUser)->postJson("/api/v1/admin/excuses/{$id}/decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');

        $a = Attendance::where('registration_id', $r->id)->where('program_session_id', $sessions[0]->id)->first();
        $this->assertSame('excused', $a->status);
        $this->assertSame($id, $a->excuse_id);
        $this->assertEquals(100, $r->fresh()->attendance_percent);
        $this->assertTrue(AppNotification::where('user_id', $trainee->id)->where('type', 'excuse.decided')->exists());
        $this->artisan('tedc:operations-hourly')->assertSuccessful();
        $this->assertSame(0, AbsenceAlert::where('registration_id', $r->id)->count());
        $this->assertNotNull($coordinator);
    }

    public function test_the_policy_can_count_excused_days_as_attended(): void
    {
        [, $r, $sessions, , $managerUser] = $this->world(4);
        $this->present($r, $sessions, [0]);
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/attendance', ['excuse_counts_as_attended' => true])->assertOk();
        $id = AbsenceExcuse::create(['registration_id' => $r->id, 'session_id' => $sessions[0]->id, 'employee_id' => $r->employee_id, 'reason_code' => 'other', 'status' => 'pending', 'manager_id' => $managerUser->id])->id;

        $this->asUser($managerUser)->postJson("/api/v1/admin/excuses/{$id}/decision", ['decision' => 'approved'])->assertOk();

        $this->assertEquals(100, $r->fresh()->attendance_percent);
    }

    public function test_leave_minutes_are_deducted_and_restored_and_the_trainee_is_told(): void
    {
        [, $r, $sessions, $coordinator] = $this->world(2);
        $this->present($r, $sessions, []);
        $a = Attendance::where('registration_id', $r->id)->where('program_session_id', $sessions[0]->id)->first();

        $res = $this->asUser($coordinator)->postJson("/api/v1/admin/attendance/{$a->id}/leaves", ['type' => 'early_leave', 'minutes' => 30, 'reason' => 'Appointment'])->assertCreated();
        $this->assertSame(90, $a->fresh()->minutes_attended);
        $this->assertSame(30, $a->fresh()->leave_minutes);
        $this->assertTrue(AppNotification::where('user_id', $r->employee->user_id)->where('type', 'leave.recorded')->exists());

        $this->asUser($coordinator)->postJson("/api/v1/admin/attendance/{$a->id}/leaves", ['type' => 'temporary', 'minutes' => 200])->assertStatus(422);
        $this->asUser($coordinator)->deleteJson('/api/v1/admin/attendance-leaves/'.$res->json('data.id'))->assertOk();
        $this->assertSame(120, $a->fresh()->minutes_attended);
        $this->assertSame(0, $a->fresh()->leave_minutes);
    }
}
