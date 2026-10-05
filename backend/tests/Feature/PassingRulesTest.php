<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Evaluation;
use App\Models\PassException;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\TrainingGroup;
use App\Services\CertificateService;
use App\Services\PassingPolicyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PassingRulesTest extends TestCase
{
    private function setup07(array $program = [], array $reg = []): array
    {
        $p = $this->makeProgram($program + ['has_course' => true, 'course_completion_percent' => 90]);
        $this->makeSession($p, now()->addDays(10));
        $employee = $this->makeEmployee();
        $r = Registration::create(['program_id' => $p->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED] + $reg);

        return [$p, $r, $employee->user];
    }

    private function policy(string $scope, ?string $id, array $over = []): array
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $body = $over + ['mode' => 'weighted', 'pass_threshold' => 71, 'criteria' => [
            ['key' => 'attendance', 'required' => true, 'min' => 80, 'weight' => 60], ['key' => 'course', 'required' => false, 'min' => 90, 'weight' => 40],
        ]];

        return $this->asUser($admin)->putJson("/api/v1/admin/passing-policies/{$scope}".($id ? "/{$id}" : ''), $body)->assertOk()->json('data');
    }

    public function test_weighted_score_decides_at_the_exact_threshold_and_required_minimums_override_it(): void
    {
        [$p, $r] = $this->setup07();
        $r->update(['attendance_percent' => 85, 'course_percent' => 50]);
        $this->policy('program', $p->id);                       // 85 × .6 + 50 × .4 = 71 → exactly the pass mark

        $e = app(PassingPolicyService::class)->evaluate($r->fresh());
        $this->assertEquals(71.0, $e['weighted_score']);
        $this->assertTrue($e['passed']);
        $this->assertSame('passed', $r->fresh()->pass_status === 'pending' ? app(PassingPolicyService::class)->recompute($r->fresh())->pass_status : $r->fresh()->pass_status);

        $this->policy('program', $p->id, ['pass_threshold' => 72]);
        $this->assertFalse(app(PassingPolicyService::class)->evaluate($r->fresh())['passed']);

        // A required minimum beats a high weighted score.
        $r->update(['attendance_percent' => 70, 'course_percent' => 100]);
        $this->policy('program', $p->id, ['pass_threshold' => 50]);
        $e = app(PassingPolicyService::class)->evaluate($r->fresh());
        $this->assertGreaterThan(50, $e['weighted_score']);
        $this->assertFalse($e['passed']);
    }

    public function test_weights_must_add_up_to_one_hundred(): void
    {
        [$p] = $this->setup07();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson("/api/v1/admin/passing-policies/program/{$p->id}", ['mode' => 'weighted', 'criteria' => [['key' => 'attendance', 'weight' => 70], ['key' => 'course', 'weight' => 20]]])->assertStatus(422)->assertJsonValidationErrors('criteria');
    }

    public function test_the_group_policy_beats_the_program_policy_which_beats_the_global_one(): void
    {
        [$p, $r] = $this->setup07();
        $group = TrainingGroup::where('program_id', $p->id)->first();
        $r->update(['training_group_id' => $group->id]);
        $svc = app(PassingPolicyService::class);

        $this->assertSame('default', $svc->policy($r->fresh())['source']);
        $this->policy('global', null, ['pass_threshold' => 10]);
        $this->assertSame('global', $svc->policy($r->fresh())['source']);
        $this->policy('program', $p->id, ['pass_threshold' => 20]);
        $this->assertSame('program', $svc->policy($r->fresh())['source']);
        $this->policy('group', $group->id, ['pass_threshold' => 30]);
        $row = $svc->policy($r->fresh());
        $this->assertSame('group', $row['source']);
        $this->assertEquals(30.0, $row['pass_threshold']);
    }

    public function test_without_any_policy_the_old_all_requirements_rule_still_applies(): void
    {
        [$p, $r, $user] = $this->setup07(['requires_evaluation' => true]);
        $r->update(['attendance_percent' => 100, 'course_percent' => 100, 'course_completed' => true]);

        $req = app(CertificateService::class)->requirements($r->fresh());
        $this->assertFalse($req['eligible']);                    // the program survey is missing
        Evaluation::create(['registration_id' => $r->id, 'program_id' => $p->id, 'employee_id' => $r->employee_id, 'ratings' => [], 'satisfaction_score' => 90, 'submitted_at' => now()]);
        $this->assertTrue(app(CertificateService::class)->requirements($r->fresh())['eligible']);
    }

    public function test_participation_comes_from_lessons_and_trainer_marks(): void
    {
        [$p, $r] = $this->setup07();
        $session = $p->sessions()->first();
        $r->update(['course_percent' => 50]);
        Attendance::create(['program_session_id' => $session->id, 'registration_id' => $r->id, 'employee_id' => $r->employee_id, 'status' => 'present', 'method' => 'manual']);
        $trainer = $this->makeUser(Role::TRAINER);
        $this->policy('program', $p->id, ['mode' => 'all_required', 'criteria' => [['key' => 'participation', 'required' => true, 'min' => 60, 'weight' => 0]]]);

        $svc = app(PassingPolicyService::class);
        $this->assertEquals(25.0, $svc->evaluate($r->fresh())['criteria'][0]['value']);       // lessons 50 % and no mark yet → (50 + 0) / 2
        $this->asUser($trainer)->putJson("/api/v1/admin/sessions/{$session->id}/participation", ['marks' => [['registration_id' => $r->id, 'participated' => true]]])->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertEquals(75.0, $svc->evaluate($r->fresh())['criteria'][0]['value']);       // (50 + 100) / 2
        $this->assertEquals(75.0, (float) $r->fresh()->participation_percent);
        $this->assertTrue($svc->evaluate($r->fresh())['passed']);
    }

    public function test_two_stage_task_approval_needs_the_supervisor_and_returns_loop_back_to_the_trainee(): void
    {
        [$p, $r, $user] = $this->setup07(['requires_tasks' => true]);
        $task = Task::create(['program_id' => $p->id, 'title_ar' => 'م', 'title_en' => 'T', 'submission_types' => ['text'], 'is_required' => true]);
        $this->policy('program', $p->id, ['mode' => 'all_required', 'task_approval' => 'trainer_then_supervisor', 'criteria' => [['key' => 'tasks', 'required' => true, 'min' => 100, 'weight' => 0]]]);
        $trainer = $this->makeUser(Role::TRAINER);
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $sub = $this->asUser($user)->postJson("/api/v1/me/tasks/{$task->id}/submit", ['text_response' => 'answer'])->assertCreated()->json('data');
        $this->asUser($trainer)->postJson("/api/v1/admin/submissions/{$sub['id']}/trainer-decision", ['decision' => 'returned', 'feedback' => 'more'])->assertOk()->assertJsonPath('data.status', 'changes_requested')->assertJsonPath('data.returned_count', 1);
        $this->asUser($user)->postJson("/api/v1/me/tasks/{$task->id}/submit", ['text_response' => 'better'])->assertCreated();
        $this->asUser($trainer)->postJson("/api/v1/admin/submissions/{$sub['id']}/trainer-decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'pending_final');

        $this->assertFalse(app(PassingPolicyService::class)->evaluate($r->fresh())['passed']);   // not counted until the supervisor decides
        $this->asUser($trainer)->postJson("/api/v1/admin/submissions/{$sub['id']}/final-decision", ['decision' => 'approved'])->assertForbidden();
        $this->asUser($head)->postJson("/api/v1/admin/submissions/{$sub['id']}/final-decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue(app(PassingPolicyService::class)->evaluate($r->fresh())['passed']);
        $this->asUser($head)->postJson("/api/v1/admin/submissions/{$sub['id']}/final-decision", ['decision' => 'approved'])->assertStatus(422)->assertJsonPath('code', 'task_not_final');
    }

    public function test_self_assessed_tasks_are_approved_automatically_in_automatic_programs_only(): void
    {
        [$p, $r, $user] = $this->setup07(['requires_tasks' => true]);
        $auto = Task::create(['program_id' => $p->id, 'title_ar' => 'م', 'title_en' => 'T', 'submission_types' => ['text'], 'is_required' => true, 'self_assessed' => true]);
        $this->asUser($user)->postJson("/api/v1/me/tasks/{$auto->id}/submit", ['text_response' => 'x'])->assertCreated()->assertJsonPath('data.status', 'submitted');   // default mode: trainer

        $this->policy('program', $p->id, ['mode' => 'all_required', 'task_approval' => 'auto', 'criteria' => [['key' => 'tasks', 'required' => true, 'min' => 100, 'weight' => 0]]]);
        $this->asUser($user)->postJson("/api/v1/me/tasks/{$auto->id}/submit", ['text_response' => 'y'])->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->assertSame('auto', TaskSubmission::first()->trainer_decision);
    }

    public function test_passing_the_comprehensive_test_without_attending_issues_a_pass_certificate(): void
    {
        [$p, $r, $user] = $this->setup07(['has_course' => false]);
        $bank = QuestionBank::create(['title_ar' => 'ب', 'title_en' => 'B', 'visibility' => 'center']);
        $q = Question::create(['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => 'س', 'difficulty' => 'easy', 'points' => 1, 'version' => 1, 'status' => 'active', 'payload' => ['options' => [['id' => 'a', 'text_ar' => 'صح', 'correct' => true], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => false]]]]);
        $test = Assessment::create(['program_id' => $p->id, 'kind' => 'comprehensive', 'title_ar' => 'ش', 'title_en' => 'C', 'max_attempts' => 2, 'pass_percent' => 50, 'status' => 'published']);
        $test->sections()->create(['selection' => 'fixed', 'question_ids' => [$q->id], 'sort_order' => 0]);

        $this->asUser($user)->postJson("/api/v1/me/programs/{$p->id}/test-out/start")->assertStatus(422)->assertJsonPath('code', 'test_out_off');
        $this->policy('program', $p->id, ['mode' => 'all_required', 'allow_test_out' => true, 'test_out_assessment_id' => $test->id, 'criteria' => [['key' => 'attendance', 'required' => true, 'min' => 80, 'weight' => 0]]]);
        $this->assertSame('pending', $r->fresh()->pass_status);

        $s = $this->asUser($user)->postJson("/api/v1/me/programs/{$p->id}/test-out/start")->assertOk()->json('data');
        $this->asUser($user)->putJson("/api/v1/me/attempts/{$s['id']}/answers", ['answers' => [$s['questions'][0]['id'] => 'a']])->assertOk();
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$s['id']}/submit")->assertOk();

        $r->refresh();
        $this->assertSame('passed', $r->pass_status);
        $this->assertSame('test_out', $r->passed_via);
        $cert = Certificate::where('registration_id', $r->id)->first();
        $this->assertNotNull($cert);
        $this->assertSame('pass', $cert->type);
        $this->assertEquals(0.0, (float) $r->attendance_percent);   // never attended
    }

    public function test_exceptions_need_a_reason_and_an_attachment_are_audited_and_revocable(): void
    {
        Storage::fake('local');
        [$p, $r] = $this->setup07(['has_course' => false]);
        $r->update(['attendance_percent' => 10]);
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $svc = app(PassingPolicyService::class);
        $this->policy('program', $p->id, ['mode' => 'all_required', 'criteria' => [['key' => 'attendance', 'required' => true, 'min' => 80, 'weight' => 0]]]);
        $this->assertFalse($svc->evaluate($r->fresh())['passed']);

        $this->asUser($admin)->postJson("/api/v1/admin/registrations/{$r->id}/exceptions", ['criterion' => 'attendance'])->assertStatus(422)->assertJsonValidationErrors(['reason', 'attachment']);
        $this->asUser($admin)->postJson("/api/v1/admin/registrations/{$r->id}/exceptions", ['criterion' => 'attendance', 'reason' => 'Hospital stay', 'attachment' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf')])->assertCreated();

        $this->assertSame('exempted', $r->fresh()->pass_status);
        $this->assertSame('exception', $r->fresh()->passed_via);
        $this->assertGreaterThan(0, AuditLog::where('auditable_type', (new PassException)->getMorphClass())->count());

        $exception = PassException::first();
        $this->asUser($admin)->deleteJson("/api/v1/admin/pass-exceptions/{$exception->id}")->assertOk();
        $this->assertNotSame('exempted', $r->fresh()->pass_status);
        $this->assertNull($r->fresh()->passed_via);
    }

    public function test_attendance_and_pass_certificates_with_total_or_actual_hours_and_the_survey_toggle(): void
    {
        [$p, $r, $user] = $this->setup07(['has_course' => false, 'total_hours' => 12]);
        $session = $p->sessions()->first();
        Attendance::create(['program_session_id' => $session->id, 'registration_id' => $r->id, 'employee_id' => $r->employee_id, 'status' => 'present', 'method' => 'manual', 'minutes_attended' => 90]);
        $r->update(['attendance_percent' => 85]);
        $this->policy('program', $p->id, ['mode' => 'all_required', 'certificate_types' => 'both', 'hours_mode' => 'actual', 'attendance_certificate_min' => 80, 'survey_required_for_download' => false,
            'criteria' => [['key' => 'attendance', 'required' => true, 'min' => 90, 'weight' => 0]]]);
        $svc = app(CertificateService::class);

        $att = $svc->issue($r->fresh(), null, 'attendance');                                    // 85 % ≥ 80 %: attendance certificate
        $this->assertSame('attendance', $att->type);
        $this->assertEquals(1.5, (float) $att->hours);                                          // actual hours, rounded to half an hour
        $this->assertEquals(12.0, (float) $att->hours_total);
        $this->assertTrue($svc->downloadable($att));                                            // survey not required by this policy
        $this->expectExceptionMessage(__('messages.certificate.blocked'));
        try {
            $svc->issue($r->fresh(), null, 'pass');                                             // 85 % < 90 %: no pass certificate
        } finally {
            $this->assertSame(1, Certificate::where('registration_id', $r->id)->count());
            $r->update(['attendance_percent' => 95]);
            $pass = $svc->issue($r->fresh(), null, 'pass');
            $this->assertSame(2, Certificate::where('registration_id', $r->id)->count());
            $this->assertSame('pass', $r->fresh()->certificate->type);                          // the main certificate is the pass one
            $this->assertSame('pass', $svc->verify($pass->verification_code)['type']);
            $this->assertSame('attendance', $svc->verify($att->verification_code)['type']);
        }
    }

    public function test_the_survey_gate_stays_on_by_default(): void
    {
        [$p, $r] = $this->setup07(['has_course' => false]);
        $r->update(['attendance_percent' => 100]);
        $cert = app(CertificateService::class)->issue($r->fresh());
        $this->assertFalse(app(CertificateService::class)->downloadable($cert));
    }

    public function test_the_simulation_shows_who_would_pass_without_saving_anything(): void
    {
        [$p, $r] = $this->setup07();
        $r->update(['attendance_percent' => 90, 'course_percent' => 90]);
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $res = $this->asUser($admin)->postJson('/api/v1/admin/passing-policies/preview', ['program_id' => $p->id, 'mode' => 'weighted', 'pass_threshold' => 95, 'criteria' => [['key' => 'attendance', 'weight' => 50], ['key' => 'course', 'weight' => 50]]])->assertOk();
        $res->assertJsonPath('data.total', 1)->assertJsonPath('data.would_pass', 0);
        $this->assertDatabaseCount('passing_policies', 0);
    }
}
