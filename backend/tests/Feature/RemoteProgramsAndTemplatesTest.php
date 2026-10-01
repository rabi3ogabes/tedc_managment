<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CertificateTemplate;
use App\Models\Registration;
use App\Models\Role;
use App\Services\CertificateService;
use App\Services\CertificateTemplateService;
use App\Services\Notifications\NotificationRoute;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RemoteProgramsAndTemplatesTest extends TestCase
{
    private function onlineSetup(): array
    {
        $program = $this->makeProgram(['delivery_mode' => 'online', 'remote' => ['platform' => 'zoom', 'join_url' => 'https://zoom.example/j/1', 'passcode' => '123', 'join_opens_minutes' => 15]]);
        $session = $this->makeSession($program, now()->addMinutes(5));
        $session->update(['mode' => 'online', 'online_url' => 'https://zoom.example/j/1', 'online_platform' => 'zoom', 'online_passcode' => '123']);
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        return [$program, $session, $employee, $registration];
    }

    public function test_joining_an_online_session_records_attendance_and_gives_the_link_only_inside_the_window(): void
    {
        [$program, $session, $employee] = $this->onlineSetup();
        $user = $employee->user;

        $this->asUser($user)->getJson("/api/v1/me/sessions/{$session->id}")->assertOk()
            ->assertJsonPath('data.mode', 'online')->assertJsonPath('data.online.can_join', true)->assertJsonMissingPath('data.online.join_url');

        $this->asUser($user)->postJson("/api/v1/me/sessions/{$session->id}/join")->assertOk()
            ->assertJsonPath('data.join_url', 'https://zoom.example/j/1')->assertJsonPath('data.attendance.status', 'present');
        $this->asUser($user)->postJson("/api/v1/me/sessions/{$session->id}/join")->assertOk()->assertJsonPath('data.attendance.join_count', 2);
        $this->asUser($user)->postJson("/api/v1/me/sessions/{$session->id}/leave")->assertOk();

        $far = $this->makeSession($program, now()->addDays(2));
        $far->update(['mode' => 'online', 'online_url' => 'https://zoom.example/j/2']);
        $this->asUser($user)->postJson("/api/v1/me/sessions/{$far->id}/join")->assertStatus(422)->assertJsonPath('code', 'not_open');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson("/api/v1/me/sessions/{$session->id}")->assertStatus(404);
    }

    public function test_admin_tracks_remote_attendance_and_reminds_those_who_did_not_join(): void
    {
        [$program, $session, $employee] = $this->onlineSetup();
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $this->asUser($admin)->getJson("/api/v1/admin/programs/{$program->id}/remote-tracking")->assertOk()
            ->assertJsonPath('data.sessions.0.not_joined', 1)->assertJsonPath('data.summary.participants', 1);

        $this->asUser($admin)->postJson("/api/v1/admin/sessions/{$session->id}/remind")->assertOk()->assertJsonPath('data.reminded', 1);
        $n = AppNotification::where('user_id', $employee->user_id)->where('type', 'session.attendance_missed')->first();
        $this->assertSame('/sessions/'.$session->id, $n->data['route']);
        $this->asUser($admin)->postJson("/api/v1/admin/sessions/{$session->id}/remind")->assertStatus(422);

        $this->asUser($employee->user)->postJson("/api/v1/me/sessions/{$session->id}/join")->assertOk();
        $this->asUser($admin)->getJson("/api/v1/admin/programs/{$program->id}/remote-tracking")->assertJsonPath('data.sessions.0.joined', 1);
    }

    public function test_notifications_carry_the_page_they_open(): void
    {
        $id = 'abc';
        $this->assertSame('/scan', NotificationRoute::for('session.attendance_open', ['session_id' => $id, 'route' => '/scan']));
        $this->assertSame('/sessions/abc', NotificationRoute::for('session.reminder', ['session_id' => $id]));
        $this->assertSame('/tasks/abc', NotificationRoute::for('task.approved', ['task_id' => $id]));
        $this->assertSame('/certificates', NotificationRoute::for('certificate.available', ['certificate_id' => $id]));
        $this->assertSame('/registrations/abc', NotificationRoute::for('registration.approved', ['registration_id' => $id, 'program_id' => 'p']));
        $this->assertSame('/my-program/p', NotificationRoute::for('survey.open', ['program_id' => 'p', 'route' => '/training']));
        $this->assertSame('/surveys/abc', NotificationRoute::for('impact.survey', ['survey_id' => $id]));
        $this->assertSame('/notifications', NotificationRoute::for('announcement', ['announcement_id' => $id]));
    }

    public function test_templates_are_designed_previewed_assigned_and_used_for_certificates(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $list = $this->asUser($admin)->getJson('/api/v1/admin/certificate-templates')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertTrue(collect($list)->every(fn ($t) => $t['is_default']));

        $copy = $this->asUser($admin)->postJson('/api/v1/admin/certificate-templates', ['name_ar' => 'نسخة', 'name_en' => 'Copy', 'kind' => 'trainee', 'duplicate_of' => $list[0]['id']])->assertCreated()->json('data');
        $elements = collect($copy['elements'])->map(fn ($e) => $e['id'] === 'title' ? $e + ['text' => 'تصميم خاص {{name}}'] : $e)->all();
        $this->asUser($admin)->putJson("/api/v1/admin/certificate-templates/{$copy['id']}", ['elements' => $elements])->assertOk();

        $image = UploadedFile::fake()->image('bg.png', 1200, 850);
        $this->asUser($admin)->post("/api/v1/admin/certificate-templates/{$copy['id']}/background", ['image' => $image, 'width_mm' => 297, 'height_mm' => 210], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_background', true);
        $this->asUser($admin)->get("/api/v1/admin/certificate-templates/{$copy['id']}/file/background")->assertOk();

        $this->asUser($admin)->postJson('/api/v1/admin/certificate-templates/preview', ['template_id' => $copy['id'], 'width_mm' => 297, 'height_mm' => 210, 'elements' => $elements])
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $program = $this->makeProgram(['requires_evaluation' => false]);
        $this->asUser($admin)->putJson("/api/v1/admin/programs/{$program->id}", ['certificate_template_id' => $copy['id']])->assertOk()->assertJsonPath('data.certificate_template_id', $copy['id']);

        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED, 'attendance_percent' => 100]);
        $certificate = app(CertificateService::class)->issue($registration->load('program', 'employee.user'));
        $this->assertStringStartsWith('%PDF', app(CertificateService::class)->render($certificate));
        $this->assertSame($copy['id'], app(CertificateTemplateService::class)->resolve('trainee', $program->fresh())->id);

        $this->asUser($admin)->deleteJson("/api/v1/admin/certificate-templates/{$list[0]['id']}")->assertStatus(422);
        $this->asUser($admin)->deleteJson("/api/v1/admin/certificate-templates/{$copy['id']}")->assertOk();
        $this->assertNull($program->fresh()->certificate_template_id);
        $this->assertSame(1, CertificateTemplate::where('kind', 'trainee')->count());
    }
}
