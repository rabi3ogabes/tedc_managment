<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Certificate;
use App\Models\Evaluation;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainerCertificate;
use App\Services\CertificateService;
use App\Services\TrainerCertificateService;
use Tests\TestCase;

class CertificateAccessTest extends TestCase
{
    private function issued(): Certificate
    {
        $program = $this->makeProgram(['requires_evaluation' => false]);
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED, 'attendance_percent' => 100]);

        return app(CertificateService::class)->issue($registration->load('program', 'employee.user'));
    }

    public function test_trainee_downloads_only_after_the_survey_and_is_notified(): void
    {
        $certificate = $this->issued();
        $user = $certificate->employee->user;

        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'certificate.survey_needed']);
        $this->asUser($user)->getJson('/api/v1/me/certificates')->assertOk()->assertJsonPath('data.0.downloadable', false)->assertJsonPath('data.0.survey_required', true);
        $this->asUser($user)->getJson("/api/v1/certificates/{$certificate->id}/download")->assertStatus(422)->assertJsonPath('code', 'survey_required');

        Evaluation::create(['registration_id' => $certificate->registration_id, 'program_id' => $certificate->program_id, 'employee_id' => $certificate->employee_id, 'ratings' => [5], 'satisfaction_score' => 100, 'submitted_at' => now()]);
        app(CertificateService::class)->announce($certificate->fresh());
        app(CertificateService::class)->announce($certificate->fresh());

        $this->assertSame(1, AppNotification::where('user_id', $user->id)->where('type', 'certificate.available')->count());
        $this->asUser($user)->getJson('/api/v1/me/certificates')->assertJsonPath('data.0.downloadable', true);
        $this->asUser($user)->get("/api/v1/certificates/{$certificate->id}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_trainer_certificate_is_issued_after_all_hours_are_delivered(): void
    {
        $program = $this->makeProgram();
        $user = $this->makeUser(Role::EMPLOYEE);
        $trainer = Trainer::create(['name_en' => 'Sam', 'name_ar' => 'سامر', 'user_id' => $user->id, 'status' => 'active', 'source' => 'center']);
        $past = $this->makeSession($program, now()->subDays(2));
        $future = $this->makeSession($program, now()->addDays(2));
        $past->update(['trainer_id' => $trainer->id]);
        $future->update(['trainer_id' => $trainer->id]);
        $service = app(TrainerCertificateService::class);

        $this->assertNull($service->issueIfComplete($trainer, $program));
        $this->asUser($user)->getJson('/api/v1/me/trainer-certificates')->assertOk()->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.sessions_done', 1);

        $future->update(['status' => 'completed']);
        $this->assertSame(1, $service->sync($program));
        $this->assertSame(1, $service->sync($program) + 1);

        $certificate = TrainerCertificate::firstOrFail();
        $this->assertSame(1, AppNotification::where('user_id', $user->id)->where('type', 'certificate.trainer_available')->count());
        $this->asUser($user)->getJson('/api/v1/me/trainer-certificates')->assertJsonPath('data.0.downloadable', true);
        $this->asUser($user)->get("/api/v1/trainer-certificates/{$certificate->id}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson("/api/v1/trainer-certificates/{$certificate->id}/download")->assertForbidden();
    }
}
