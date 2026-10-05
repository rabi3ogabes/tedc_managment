<?php

namespace Tests\Feature;

use App\Models\ProgramGrant;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskSubmission;
use Tests\TestCase;

class ProgramGrantsTest extends TestCase
{
    private function setupProgram(): array
    {
        $program = $this->makeProgram();
        $session = $this->makeSession($program, now()->subMinutes(10));
        $trainee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $trainee->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);

        return [$program, $session, $registration];
    }

    private function grant($head, $program, $user, string $ability, ?string $expires = null)
    {
        return $this->asUser($head)->postJson("/api/v1/admin/programs/{$program->id}/grants", ['user_id' => $user->id, 'ability' => $ability] + ($expires ? ['expires_at' => $expires] : []));
    }

    public function test_the_head_of_training_grants_and_revokes_attendance_rights_for_one_program(): void
    {
        [$program, $session, $registration] = $this->setupProgram();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $manager = $this->makeUser(Role::SUPERVISOR);   // a line manager: no attendance permission by role
        $mark = fn () => $this->asUser($manager)->postJson("/api/v1/admin/sessions/{$session->id}/attendance", ['registration_id' => $registration->id, 'status' => 'present']);

        $mark()->assertForbidden();

        $id = $this->grant($head, $program, $manager, 'attendance.mark')->assertCreated()->json('data.id');
        $mark()->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'program_grant_added', 'user_id' => $head->id]);

        $this->asUser($head)->deleteJson("/api/v1/admin/programs/{$program->id}/grants/{$id}")->assertOk();
        $mark()->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'program_grant_revoked']);
    }

    public function test_a_trainer_who_does_not_deliver_the_program_needs_the_grant_and_an_expired_grant_counts_for_nothing(): void
    {
        [$program, $session, $registration] = $this->setupProgram();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $trainer = $this->makeUser(Role::TRAINER);
        $mark = fn () => $this->asUser($trainer)->postJson("/api/v1/admin/sessions/{$session->id}/attendance", ['registration_id' => $registration->id, 'status' => 'present']);

        $mark()->assertForbidden()->assertJsonPath('message', __('messages.grants.attendance_denied'));

        $this->grant($head, $program, $trainer, 'attendance.mark', now()->addDay()->toDateString())->assertCreated();
        $mark()->assertOk();

        ProgramGrant::query()->update(['expires_at' => now()->subMinute()]);
        $mark()->assertForbidden();
    }

    public function test_a_grant_is_for_one_program_only(): void
    {
        [$program, $session, $registration] = $this->setupProgram();
        [$otherProgram, $otherSession, $otherRegistration] = $this->setupProgram();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $manager = $this->makeUser(Role::SUPERVISOR);
        $this->grant($head, $program, $manager, 'attendance.mark')->assertCreated();

        $this->asUser($manager)->postJson("/api/v1/admin/sessions/{$otherSession->id}/attendance", ['registration_id' => $otherRegistration->id, 'status' => 'present'])->assertForbidden();
    }

    public function test_supervisors_can_be_given_the_right_to_notify_a_programs_trainees_and_to_review_its_tasks(): void
    {
        [$program, $session, $registration] = $this->setupProgram();
        [$otherProgram] = $this->setupProgram();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $manager = $this->makeUser(Role::SUPERVISOR);
        $send = fn ($p) => $this->asUser($manager)->postJson('/api/v1/admin/notifications/send', ['program_id' => $p->id, 'title_ar' => 'تنبيه', 'title_en' => 'Notice', 'body_ar' => 'نص', 'body_en' => 'Text']);

        $send($program)->assertForbidden();
        $this->grant($head, $program, $manager, 'notifications.send')->assertCreated();
        $send($program)->assertCreated();
        $send($otherProgram)->assertForbidden();

        $task = Task::create(['program_id' => $program->id, 'title_ar' => 'مهمة', 'title_en' => 'Task', 'instructions_ar' => 'x', 'instructions_en' => 'x', 'submission_types' => ['text'], 'is_required' => true]);
        $submission = TaskSubmission::create(['task_id' => $task->id, 'registration_id' => $registration->id, 'employee_id' => $registration->employee_id, 'status' => 'submitted', 'text_response' => 'done']);
        $review = fn () => $this->asUser($manager)->postJson("/api/v1/admin/submissions/{$submission->id}/review", ['status' => 'approved']);

        $review()->assertForbidden();
        $this->grant($head, $program, $manager, 'tasks.review')->assertCreated();
        $review()->assertOk();
    }

    public function test_only_people_who_manage_grants_can_grant_and_the_ability_must_be_known(): void
    {
        [$program] = $this->setupProgram();
        $user = $this->makeUser(Role::SUPERVISOR);

        $this->asUser($this->makeUser(Role::TRAINER))->postJson("/api/v1/admin/programs/{$program->id}/grants", ['user_id' => $user->id, 'ability' => 'attendance.mark'])->assertForbidden();
        $this->grant($this->makeUser(Role::TRAINING_HEAD), $program, $user, 'delete.everything')->assertUnprocessable();

        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->grant($head, $program, $user, 'attendance.mark')->assertCreated();
        $this->grant($head, $program, $user, 'attendance.mark')->assertOk('granting twice only refreshes the expiry');
        $this->assertSame(1, ProgramGrant::count());
        $list = $this->asUser($head)->getJson("/api/v1/admin/programs/{$program->id}/grants")->assertOk()->json('data');
        $this->assertSame($user->id, $list[0]['user']['id']);
    }

    public function test_the_staff_lookup_finds_people_for_the_grants_screen(): void
    {
        $this->makeUser(Role::SUPERVISOR, ['name' => 'Layla Mansour', 'email' => 'layla.m@test.qa']);
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $found = $this->asUser($head)->getJson('/api/v1/admin/staff-lookup?q=layla')->assertOk()->json('data');
        $this->assertSame('layla.m@test.qa', $found[0]['email']);
        $this->asUser($head)->getJson('/api/v1/admin/staff-lookup?q=l')->assertUnprocessable();
        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/staff-lookup?q=layla')->assertForbidden();
    }
}
