<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\GroupTrainer;
use App\Models\Permission;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainerAttendance;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\Admin\ShowcaseAccounts;
use App\Services\AttendanceMethodsService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** «برامجي»: what a supervisor and a trainer were given, and the attendance of the sessions that are theirs to run. */
class MyDutiesTest extends TestCase
{
    private function trainer(): array
    {
        $user = $this->makeUser(Role::TRAINER);

        return [$user, Trainer::create(['user_id' => $user->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => $user->email, 'status' => 'active'])];
    }

    private function running(array $attributes = []): Program
    {
        return $this->makeProgram($attributes + ['status' => Program::STATUS_IN_PROGRESS, 'start_date' => today()->subDay(), 'end_date' => today()->addDay()]);
    }

    private function duties(User $as): array
    {
        return $this->asUser($as)->getJson('/api/v1/me/duties')->assertOk()->json('data.groups');
    }

    public function test_a_supervisor_sees_the_groups_he_supervises_and_only_those(): void
    {
        $supervisor = $this->makeUser(Role::SUPERVISOR);
        $other = $this->makeUser(Role::SUPERVISOR);
        $mine = $this->running(['title_ar' => 'برنامجي']);
        $mine->ensureGroup()->update(['supervisor_id' => $supervisor->id]);
        $theirs = $this->running();
        $theirs->ensureGroup()->update(['supervisor_id' => $other->id]);
        $this->makeSession($mine, now()->subHour(), 3);

        $rows = $this->duties($supervisor);
        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows[0]['program']['id']);
        $this->assertSame(['supervisor'], $rows[0]['capacities']);
        $this->assertSame('running', $rows[0]['phase']);
        $this->assertTrue($rows[0]['supervisor']['me']);
        $this->assertSame('now', $rows[0]['sessions'][0]['when']);
        $this->assertTrue($rows[0]['sessions'][0]['focus']);
        // The programme's two forms come with it.
        $this->assertArrayHasKey('trainee', $rows[0]['forms']);
        $this->assertArrayHasKey('reflection', $rows[0]['forms']);

        $this->asUser($supervisor)->getJson('/api/v1/me/duties')->assertJsonPath('data.summary.supervising', 1)->assertJsonPath('data.summary.teaching', 0)->assertJsonPath('data.summary.today', 1);
    }

    public function test_a_trainer_sees_what_he_teaches_however_it_was_assigned(): void
    {
        [$user, $trainer] = $this->trainer();
        // «البرامج»: a trainer of the programme. «سابر+»: an approved trainer of a group. And the trainer of one session.
        $byProgramme = $this->running(['title_ar' => 'ألف']);
        $byProgramme->trainers()->attach($trainer->id, ['role' => 'lead']);
        $byGroup = $this->makeProgram(['title_ar' => 'باء']);
        GroupTrainer::create(['group_id' => $byGroup->ensureGroup()->id, 'trainer_id' => $trainer->id, 'role' => 'lead', 'status' => GroupTrainer::APPROVED]);
        $bySession = $this->makeProgram(['title_ar' => 'جيم']);
        $this->makeSession($bySession, now()->addDays(10))->update(['trainer_id' => $trainer->id]);
        // Not his: only proposed, not approved; and a draft he teaches is not announced yet.
        $proposed = $this->makeProgram();
        GroupTrainer::create(['group_id' => $proposed->ensureGroup()->id, 'trainer_id' => $trainer->id, 'role' => 'lead', 'status' => GroupTrainer::PROPOSED]);
        $draft = $this->makeProgram(['status' => Program::STATUS_DRAFT]);
        $draft->trainers()->attach($trainer->id, ['role' => 'lead']);

        $rows = $this->duties($user);
        $this->assertEqualsCanonicalizing([$byProgramme->id, $byGroup->id, $bySession->id], array_column(array_column($rows, 'program'), 'id'));
        $this->assertSame($byProgramme->id, $rows[0]['program']['id'], 'the one in progress comes first');
        foreach ($rows as $row) {
            $this->assertSame(['trainer'], $row['capacities']);
        }
        $this->assertTrue(collect($rows[0]['trainers'])->contains('me', true));
    }

    public function test_one_person_can_supervise_and_teach_and_somebody_with_no_assignment_sees_nothing(): void
    {
        [$user, $trainer] = $this->trainer();
        $program = $this->running();
        $program->ensureGroup()->update(['supervisor_id' => $user->id]);
        $program->trainers()->attach($trainer->id, ['role' => 'lead']);

        $this->assertSame(['supervisor', 'trainer'], $this->duties($user)[0]['capacities']);
        $this->assertSame([], $this->duties($this->makeEmployee()->user));
    }

    public function test_a_session_counts_who_attended_out_of_the_trainees_of_the_programme(): void
    {
        $supervisor = $this->makeUser(Role::SUPERVISOR);
        $program = $this->running();
        $program->ensureGroup()->update(['supervisor_id' => $supervisor->id]);
        $session = $this->makeSession($program, now()->subHour(), 3);
        foreach ([true, true, false] as $came) {
            $employee = $this->makeEmployee();
            $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
            if ($came) {
                Attendance::create(['program_session_id' => $session->id, 'registration_id' => $registration->id, 'employee_id' => $employee->id, 'status' => 'present', 'check_in_at' => now(), 'method' => 'qr']);
            }
        }

        $row = $this->duties($supervisor)[0];
        $this->assertSame(3, $row['trainees']);
        $this->assertSame(2, $row['sessions'][0]['present']);
        $this->assertSame(3, $row['sessions'][0]['expected']);
    }

    public function test_the_supervisor_of_a_group_opens_its_attendance_and_qr_and_not_those_of_another_group(): void
    {
        $supervisor = $this->makeUser(Role::SUPERVISOR);
        $supervisor->roles()->first()->permissions()->syncWithoutDetaching(Permission::where('slug', 'attendance.manage')->pluck('id'));
        $mine = $this->running();
        $mine->ensureGroup()->update(['supervisor_id' => $supervisor->id]);
        $own = $this->makeSession($mine, now()->subHour(), 3);
        $foreign = $this->makeSession($this->running(), now()->subHour(), 3);

        $this->asUser($supervisor)->getJson("/api/v1/admin/sessions/{$own->id}/attendance")->assertOk()->assertJsonPath('data.expected', 0);
        $this->asUser($supervisor)->getJson("/api/v1/admin/sessions/{$own->id}/qr")->assertOk()->assertJsonStructure(['data' => ['payload', 'expires_at', 'session' => ['id']]]);
        // Holding the attendance right for the role does not open a session of a group he was not given.
        $this->asUser($supervisor)->getJson("/api/v1/admin/sessions/{$foreign->id}/attendance")->assertForbidden();
        $this->asUser($supervisor)->getJson("/api/v1/admin/sessions/{$foreign->id}/qr")->assertForbidden();
    }

    /** Each of the two: a programme with its group, a session on now with its trainer, and one registered trainee. */
    private function staffed(?User $supervisor = null): array
    {
        $program = $this->running();
        $group = $program->ensureGroup();
        $group->update(['supervisor_id' => $supervisor?->id]);
        $session = $this->makeSession($program, now()->subMinutes(5), 3);
        $session->update(['trainer_id' => $this->trainer()[1]->id]);
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        return [$session, $group, $employee, $registration];
    }

    public function test_the_supervisor_of_a_group_takes_its_attendance_in_every_way_and_not_that_of_another_group(): void
    {
        Storage::fake('local');
        $supervisor = $this->makeUser(Role::SUPERVISOR);
        $supervisor->roles()->first()->permissions()->syncWithoutDetaching(Permission::where('slug', 'attendance.manage')->pluck('id'));
        [$own, $ownGroup, $ownEmployee, $ownRegistration] = $this->staffed($supervisor);
        [$foreign, $foreignGroup, $foreignEmployee, $foreignRegistration] = $this->staffed($this->makeUser(Role::SUPERVISOR));
        $qr = fn ($employee) => app(AttendanceMethodsService::class)->personalQr('e', $employee->id)['payload'];
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $me = fn () => $this->asUser($supervisor);

        // His own group: the roster, the trainee's personal code, the signature, the trainers' attendance, the sheets.
        $this->assertCount(1, $me()->getJson("/api/v1/admin/sessions/{$own->id}/kiosk")->assertOk()->json('data.roster'));
        $me()->postJson("/api/v1/admin/sessions/{$own->id}/staff-scan", ['payload' => $qr($ownEmployee)])->assertOk()->assertJsonPath('data.kind', 'trainee');
        $me()->postJson("/api/v1/admin/sessions/{$own->id}/signatures", ['registration_id' => $ownRegistration->id, 'direction' => 'out', 'signature' => $png])->assertOk();
        $me()->getJson("/api/v1/admin/sessions/{$own->id}/trainer-attendance")->assertOk()->assertJsonPath('data.0.trainer_id', $own->trainer_id);
        $me()->postJson("/api/v1/admin/sessions/{$own->id}/trainer-attendance", ['trainer_id' => $own->trainer_id, 'status' => 'present'])->assertOk();
        $me()->get("/api/v1/admin/sessions/{$own->id}/attendance/export?format=xlsx")->assertOk();
        $me()->get("/api/v1/admin/groups/{$ownGroup->id}/attendance/export?format=xlsx")->assertOk();
        $this->assertStringStartsWith('%PDF', $me()->get("/api/v1/admin/sessions/{$own->id}/sheet.pdf")->assertOk()->getContent());

        // Holding the attendance right for the role opens none of it for a group he was not given, by its id.
        $me()->getJson("/api/v1/admin/sessions/{$foreign->id}/kiosk")->assertForbidden();
        $me()->postJson("/api/v1/admin/sessions/{$foreign->id}/staff-scan", ['payload' => $qr($foreignEmployee)])->assertForbidden();
        $me()->postJson("/api/v1/admin/sessions/{$foreign->id}/signatures", ['registration_id' => $foreignRegistration->id, 'direction' => 'in', 'signature' => $png])->assertForbidden();
        $me()->getJson("/api/v1/admin/sessions/{$foreign->id}/trainer-attendance")->assertForbidden();
        $me()->postJson("/api/v1/admin/sessions/{$foreign->id}/trainer-attendance", ['trainer_id' => $foreign->trainer_id, 'status' => 'present'])->assertForbidden();
        $me()->get("/api/v1/admin/sessions/{$foreign->id}/attendance/export?format=xlsx")->assertForbidden();
        $me()->get("/api/v1/admin/groups/{$foreignGroup->id}/attendance/export?format=xlsx")->assertForbidden();
        $me()->get("/api/v1/admin/sessions/{$foreign->id}/sheet.pdf")->assertForbidden();
        // Nothing was read or written there: no record, and the roster was not opened.
        $this->assertSame(0, Attendance::where('program_session_id', $foreign->id)->count());
        $this->assertSame(0, TrainerAttendance::where('session_id', $foreign->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'kiosk_opened', 'auditable_id' => $foreign->id]);

        // Centre staff run every group, as before.
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $this->asUser($coordinator)->getJson("/api/v1/admin/sessions/{$foreign->id}/kiosk")->assertOk();
        $this->asUser($coordinator)->postJson("/api/v1/admin/sessions/{$foreign->id}/staff-scan", ['payload' => $qr($foreignEmployee)])->assertOk();
        $this->asUser($coordinator)->get("/api/v1/admin/groups/{$foreignGroup->id}/attendance/export?format=xlsx")->assertOk();
    }

    public function test_a_trainer_approved_for_a_group_opens_the_sessions_of_that_group(): void
    {
        [$user, $trainer] = $this->trainer();
        $program = $this->running();
        $group = $program->ensureGroup();
        GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $trainer->id, 'role' => 'lead', 'status' => GroupTrainer::APPROVED]);
        $session = $this->makeSession($program, now()->subHour(), 3);
        $session->update(['training_group_id' => $group->id]);
        // Another group of the same programme is not his.
        $second = TrainingGroup::create(['program_id' => $program->id, 'code' => $program->code.'-G2', 'sequence' => 2, 'status' => 'planned']);
        $elsewhere = $this->makeSession($program, now()->subHour(), 3);
        $elsewhere->update(['training_group_id' => $second->id]);

        $this->asUser($user)->getJson("/api/v1/admin/sessions/{$session->id}/qr")->assertOk();
        $this->asUser($user)->getJson("/api/v1/admin/sessions/{$elsewhere->id}/qr")->assertForbidden();
        $this->assertSame([$group->id], array_column($this->duties($user), 'id'));
    }

    public function test_the_showcase_supervisors_are_given_groups_and_a_supervisor_and_a_trainer_are_trainees_too(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $showcase = app(ShowcaseAccounts::class);
        $showcase->build($admin);

        $first = $showcase->find('supervisor-1');
        $rows = $this->duties($first);
        $this->assertCount(2, $rows, 'this week\'s programme and next week\'s');
        $this->assertSame('running', $rows[0]['phase']);
        $this->assertSame(['supervisor'], $rows[0]['capacities']);
        $this->assertNotEmpty($rows[0]['trainers']);
        $this->assertCount(1, $this->duties($showcase->find('supervisor-2')));
        $this->assertNotEmpty($this->duties($showcase->find('trainer-1')));

        // The same people are trainees of a programme they do not run.
        $this->assertTrue($first->roles->pluck('slug')->contains(Role::EMPLOYEE));
        $this->assertTrue(Registration::where('employee_id', $first->employee->id)->where('status', Registration::STATUS_COMPLETED)->exists());
        $trainer = $showcase->find('trainer-2');
        $this->assertTrue($trainer->roles->pluck('slug')->contains(Role::EMPLOYEE));
        $this->assertTrue(Registration::where('employee_id', $trainer->employee->id)->where('status', Registration::STATUS_APPROVED)->exists());

        // Accounts built before supervisors were assigned get them without the story being rebuilt.
        TrainingGroup::whereIn('program_id', Program::where('code', 'like', ShowcaseAccounts::PREFIX.'-%')->pluck('id'))->update(['supervisor_id' => null]);
        $this->assertSame([], $this->duties($first));
        $this->assertSame(3, $showcase->assignSupervisors());
        $this->assertCount(2, $this->duties($first));
        $this->assertSame(0, ProgramSession::whereNull('program_id')->count());
    }
}
