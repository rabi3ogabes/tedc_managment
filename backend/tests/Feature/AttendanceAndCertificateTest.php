<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\ImpactSurvey;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Services\AttendanceService;
use App\Services\EvaluationSettings;
use App\Services\ImpactService;
use Tests\TestCase;

class AttendanceAndCertificateTest extends TestCase
{
    private function approvedRegistration(array $programAttributes = []): Registration
    {
        $program = $this->makeProgram($programAttributes);
        $employee = $this->makeEmployee();

        return Registration::create([
            'program_id' => $program->id, 'employee_id' => $employee->id,
            'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED,
        ])->load(['program', 'employee.user']);
    }

    public function test_dynamic_qr_check_in_and_check_out(): void
    {
        $registration = $this->approvedRegistration();
        $session = $this->makeSession($registration->program, now()->subMinutes(5));
        $coordinator = $this->makeUser(Role::COORDINATOR);

        $payload = $this->asUser($coordinator)->getJson("/api/v1/admin/sessions/{$session->id}/qr")->assertOk()->json('data.payload');
        $this->assertStringStartsWith('TEDC1.'.$session->id, $payload);

        $user = $registration->employee->user;
        $this->asUser($user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload])
            ->assertOk()->assertJsonPath('data.action', 'check_in')->assertJsonPath('data.attendance.status', 'present');

        $this->travel(60)->minutes();
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];
        $this->asUser($user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload])
            ->assertOk()->assertJsonPath('data.action', 'check_out');

        $this->assertEqualsWithDelta(50.0, $registration->fresh()->attendance_percent, 5.0);
    }

    public function test_a_program_that_requires_biometrics_refuses_a_scan_without_it(): void
    {
        $registration = $this->approvedRegistration(['require_biometric' => true]);
        $session = $this->makeSession($registration->program, now()->subMinutes(5));
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];
        $user = $registration->employee->user;

        $this->asUser($user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload])->assertStatus(422)->assertJsonPath('code', 'biometric_required');
        $this->assertSame(0, Attendance::count());

        $this->asUser($user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload, 'biometric' => true])->assertOk()->assertJsonPath('data.action', 'check_in');
        $this->assertTrue((bool) Attendance::first()->biometric_verified);
        $this->asUser($user)->getJson("/api/v1/me/sessions/{$session->id}")->assertJsonPath('data.biometric_required', true);
    }

    public function test_scanning_again_while_present_only_offers_exit_and_the_administrator_sees_every_refusal(): void
    {
        $registration = $this->approvedRegistration();
        $session = $this->makeSession($registration->program, now()->subMinutes(5));
        $qr = fn () => app(AttendanceService::class)->currentQr($session)['payload'];
        $user = $registration->employee->user;
        $scan = fn (array $extra = []) => $this->asUser($user)->postJson('/api/v1/me/attendance/scan', ['payload' => $qr()] + $extra);

        // Cannot exit before entering.
        $scan(['intent' => 'check_out'])->assertStatus(422)->assertJsonPath('code', 'not_checked_in');
        $scan(['intent' => 'check_in'])->assertOk()->assertJsonPath('data.action', 'check_in');

        // Already present: nothing changes, the answer says so.
        $res = $scan(['intent' => 'check_in'])->assertOk()->assertJsonPath('data.action', 'already_present');
        $this->assertStringContainsString('الخروج', $res->json('data.message'));
        $this->assertNull(Attendance::first()->check_out_at);

        // The exit button.
        $scan(['intent' => 'check_out'])->assertOk()->assertJsonPath('data.action', 'check_out');
        $scan(['intent' => 'check_in'])->assertStatus(422)->assertJsonPath('code', 'already_checked_out');

        // A participant of another program is refused.
        $other = $this->makeEmployee();
        $this->asUser($other->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $qr(), 'intent' => 'check_in'])->assertStatus(422)->assertJsonPath('code', 'not_registered');

        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $rows = collect($this->asUser($admin)->getJson('/api/v1/admin/attendance-attempts')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['not_checked_in', 'already_present', 'already_checked_out', 'not_registered'], $rows->pluck('code')->all());
        $this->assertSame($session->id, $rows->firstWhere('code', 'not_registered')['session']['id']);
        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/attendance-attempts')->assertForbidden();
    }

    public function test_the_programs_list_filters_by_delivery_type_and_counts_each_type(): void
    {
        $this->makeProgram(['delivery_mode' => 'online']);
        $this->makeProgram(['delivery_mode' => 'online']);
        $this->makeProgram(['delivery_mode' => 'hybrid']);
        $this->makeProgram(['delivery_mode' => 'in_person']);
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $res = $this->asUser($admin)->getJson('/api/v1/admin/programs?delivery_mode=online')->assertOk();
        $this->assertCount(2, $res->json('data'));
        $this->assertSame(['in_person' => 1, 'online' => 2, 'hybrid' => 1], $res->json('types'));
    }

    public function test_expired_or_forged_qr_is_rejected(): void
    {
        $registration = $this->approvedRegistration();
        $session = $this->makeSession($registration->program, now());
        $service = app(AttendanceService::class);

        $old = $service->currentQr($session, time() - 300)['payload'];
        $this->asUser($registration->employee->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $old])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_qr');

        $forged = substr($service->currentQr($session)['payload'], 0, -4).'0000';
        $this->asUser($registration->employee->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $forged])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_qr');
    }

    public function test_unregistered_employee_cannot_check_in(): void
    {
        $registration = $this->approvedRegistration();
        $session = $this->makeSession($registration->program, now());
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];

        $this->asUser($this->makeEmployee()->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload])
            ->assertStatus(422)->assertJsonPath('code', 'not_registered');
    }

    public function test_certificate_blocked_until_all_requirements_met_then_issued(): void
    {
        $registration = $this->approvedRegistration(['requires_tasks' => true, 'requires_evaluation' => true]);
        $program = $registration->program;
        $session = $this->makeSession($program, now()->subHours(3));
        $skill = Skill::where('code', 'ai_in_education')->first();
        $program->skills()->attach($skill->id, ['target_level' => 4]);
        $task = Task::create(['program_id' => $program->id, 'title_ar' => 'مهمة', 'title_en' => 'Task', 'submission_types' => ['text'], 'is_required' => true]);
        $coordinator = $this->makeUser(Role::COORDINATOR);

        $this->asUser($coordinator)->postJson("/api/v1/admin/registrations/{$registration->id}/certificate")
            ->assertStatus(422)->assertJsonPath('code', 'certificate_blocked');

        // Attendance via manual marking.
        $this->asUser($coordinator)->postJson("/api/v1/admin/sessions/{$session->id}/attendance", ['registration_id' => $registration->id, 'status' => 'present'])->assertOk();
        $this->assertSame(100.0, $registration->fresh()->attendance_percent);

        // Task submission + approval.
        $user = $registration->employee->user;
        $this->asUser($user)->post("/api/v1/me/tasks/{$task->id}/submit", ['text_response' => 'خطة التطبيق'], ['Accept' => 'application/json'])->assertCreated();
        $submission = TaskSubmission::first();
        $this->asUser($coordinator)->postJson("/api/v1/admin/submissions/{$submission->id}/review", ['status' => 'changes_requested'])->assertStatus(422);
        $this->asUser($coordinator)->postJson("/api/v1/admin/submissions/{$submission->id}/review", ['status' => 'approved'])->assertOk();

        // Evaluation.
        $this->asUser($user)->postJson("/api/v1/me/registrations/{$registration->id}/evaluation", [
            'ratings' => ['content' => 5, 'trainer' => 4], 'post_test_score' => 90,
        ])->assertCreated();
        $this->assertSame('eligible', $registration->fresh()->certificate_status);

        $this->asUser($coordinator)->postJson("/api/v1/admin/registrations/{$registration->id}/certificate")
            ->assertCreated()->assertJsonStructure(['data' => ['certificate_no', 'verification_code', 'verification_url']]);

        $certificate = Certificate::first();
        $this->assertNotNull($certificate->file_path);
        $this->assertSame(Registration::STATUS_COMPLETED, $registration->fresh()->status);
        // One trainee impact form by default (at 45 days); the optional second one is a setting.
        $this->assertSame(1, ImpactSurvey::where('registration_id', $registration->id)->count());
        $this->assertSame(4, (int) $registration->employee->skills()->where('skills.id', $skill->id)->first()->pivot->level);

        // Public verification & PDF download.
        $this->getJson("/api/v1/public/certificates/verify/{$certificate->verification_code}")->assertOk()->assertJsonPath('data.valid', true);
        $this->asUser($user)->get("/api/v1/certificates/{$certificate->id}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asUser($this->makeEmployee()->user)->get("/api/v1/certificates/{$certificate->id}/download")->assertForbidden();
    }

    public function test_impact_score_blends_available_signals(): void
    {
        $registration = $this->approvedRegistration();
        $registration->update(['attendance_percent' => 100, 'status' => Registration::STATUS_COMPLETED, 'completed_at' => now()->subDays(50)]);
        // The trainee form goes out at least one and a half months after the program (45 days by default); a second one at 90 days is optional.
        app(EvaluationSettings::class)->update(['impact' => ['optional_days' => 90]]);
        $service = app(ImpactService::class);
        $service->scheduleFollowUps($registration);

        $survey = ImpactSurvey::where('registration_id', $registration->id)->where('stage_days', 45)->first();
        $this->asUser($registration->employee->user)->postJson("/api/v1/me/surveys/{$survey->id}", [
            'applied_learning' => 'yes', 'application_score' => 80, 'needs_support' => false,
        ])->assertOk();

        $breakdown = $service->breakdown($registration->fresh());
        // attendance 100 (w15) + application 80 (w25) + follow-up 100% (w15) => (1500+2000+1500)/55
        $this->assertEqualsWithDelta(90.91, $breakdown['score'], 0.01);

        $future = ImpactSurvey::where('registration_id', $registration->id)->where('stage_days', 90)->first();
        $this->asUser($registration->employee->user)->postJson("/api/v1/me/surveys/{$future->id}", ['applied_learning' => 'yes', 'needs_support' => false])
            ->assertStatus(422)->assertJsonPath('code', 'survey_not_due');
    }
}
