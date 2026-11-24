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
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\Admin\ShowcaseAccounts;
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
