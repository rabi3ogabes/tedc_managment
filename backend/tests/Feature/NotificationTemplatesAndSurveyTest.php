<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\NotificationTemplate;
use App\Models\Registration;
use App\Models\Role;
use App\Services\Notifications\ProgramSurvey;
use App\Services\RegistrationService;
use Tests\TestCase;

class NotificationTemplatesAndSurveyTest extends TestCase
{
    private function enrolled(array $programAttributes = []): array
    {
        $program = $this->makeProgram($programAttributes);
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        return [$program, $employee->user, $registration];
    }

    public function test_every_automatic_notification_has_a_template_and_can_be_switched_off_and_rewritten(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $templates = $this->asUser($admin)->getJson('/api/v1/admin/notifications/templates')->assertOk()->json('data.templates');
        $assigned = collect($templates)->firstWhere('event', 'program.assigned');
        $this->assertTrue($assigned['enabled']);
        $this->assertContains('program', $this->asUser($admin)->getJson('/api/v1/admin/notifications/templates')->json('data.variables'));

        $program = $this->makeProgram();
        $employee = $this->makeEmployee();
        $this->asUser($admin)->putJson("/api/v1/admin/notifications/templates/{$assigned['id']}", ['title_ar' => 'مرحباً {{name}}', 'title_en' => 'Hello {{name}}', 'body_ar' => 'أُسند إليك «{{program}}»', 'body_en' => '"{{program}}" is yours'])->assertOk()->assertJsonPath('data.customised', true);

        app(RegistrationService::class)->register($program, $employee, Registration::SOURCE_CENTER, $admin);
        $n = AppNotification::where('user_id', $employee->user->id)->where('type', 'program.assigned')->first();
        $this->assertNotNull($n, 'assigning a program sends the program.assigned notification');
        $this->assertStringContainsString($employee->user->name_ar ?: $employee->user->name, $n->title_ar);
        $this->assertStringContainsString($program->title_ar, $n->body_ar);

        // Switched off: the next assignment is silent.
        $this->asUser($admin)->putJson("/api/v1/admin/notifications/templates/{$assigned['id']}", ['enabled' => false])->assertOk();
        $other = $this->makeEmployee();
        app(RegistrationService::class)->register($program, $other, Registration::SOURCE_CENTER, $admin);
        $this->assertSame(0, AppNotification::where('user_id', $other->user->id)->count());

        // Restoring the built-in wording.
        $this->asUser($admin)->postJson("/api/v1/admin/notifications/templates/{$assigned['id']}/reset")->assertOk()->assertJsonPath('data.customised', false);
    }

    public function test_custom_templates_can_be_created_previewed_and_deleted(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $created = $this->asUser($admin)->postJson('/api/v1/admin/notifications/templates', ['name_ar' => 'تنبيه', 'name_en' => 'Heads-up', 'title_ar' => 'تنبيه {{program}}', 'title_en' => 'Heads-up {{program}}', 'body_ar' => 'نص', 'body_en' => 'text'])->assertCreated()->json('data');
        $this->assertStringStartsWith('custom.', $created['event']);
        $program = $this->makeProgram();

        $preview = $this->asUser($admin)->postJson('/api/v1/admin/notifications/templates/preview', ['title_ar' => 'برنامج {{program}}', 'program_id' => $program->id])->assertOk()->json('data');
        $this->assertSame("برنامج {$program->title_ar}", $preview['title_ar']);

        $this->asUser($admin)->deleteJson("/api/v1/admin/notifications/templates/{$created['id']}")->assertOk();
        $this->asUser($admin)->getJson('/api/v1/admin/notifications/templates')->assertOk();
        $system = NotificationTemplate::where('event', 'certificate.issued')->first();
        $this->asUser($admin)->deleteJson("/api/v1/admin/notifications/templates/{$system->id}")->assertStatus(422);
        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/notifications/templates')->assertForbidden();
    }

    public function test_survey_gate_manual_open_notification_and_read_tracking(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        [$program, $trainee, $registration] = $this->enrolled();
        [, $second, $secondRegistration] = [null, ($e = $this->makeEmployee())->user, Registration::create(['program_id' => $program->id, 'employee_id' => $e->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED])];
        $ratings = ['ratings' => ['content' => 5, 'trainer' => 4]];

        // Default: available whenever (legacy behaviour).
        $this->asUser($trainee)->postJson("/api/v1/me/registrations/{$registration->id}/evaluation", $ratings)->assertStatus(201);
        $secondRegistration->evaluation()->delete();

        // The administrator makes it manual: closed until opened.
        $this->asUser($admin)->putJson("/api/v1/admin/programs/{$program->id}/survey", ['mode' => 'manual'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->asUser($second)->postJson("/api/v1/me/registrations/{$secondRegistration->id}/evaluation", $ratings)->assertStatus(422)->assertJsonPath('code', 'survey_closed');
        $this->assertFalse($this->asUser($second)->getJson("/api/v1/me/registrations/{$secondRegistration->id}")->json('data.survey_open'));

        // Opening notifies only the trainees who have not answered yet.
        $state = $this->asUser($admin)->postJson("/api/v1/admin/programs/{$program->id}/survey/open")->assertOk()->json('data');
        $this->assertSame('open', $state['status']);
        $this->assertSame(1, AppNotification::where('type', 'survey.open')->count());
        $this->assertSame($second->id, AppNotification::where('type', 'survey.open')->value('user_id'));
        $this->asUser($second)->postJson("/api/v1/me/registrations/{$secondRegistration->id}/evaluation", $ratings)->assertStatus(201);

        // Tracking: sent -> seen -> read.
        $campaign = $this->asUser($admin)->getJson('/api/v1/admin/notifications/campaigns')->assertOk()->json('data.0');
        $this->assertSame(1, $campaign['sent']);
        $this->assertSame(0, $campaign['read']);
        $notification = AppNotification::where('type', 'survey.open')->first();
        $this->asUser($second)->postJson('/api/v1/me/notifications/seen', ['ids' => [$notification->id]])->assertOk();
        $this->asUser($second)->postJson("/api/v1/me/notifications/{$notification->id}/read")->assertOk();
        $detail = $this->asUser($admin)->getJson("/api/v1/admin/notifications/campaigns/{$campaign['id']}")->assertOk()->json();
        $this->assertSame(1, $detail['data']['read']);
        $this->assertNotNull($detail['recipients']['data'][0]['read_at']);
        $csv = $this->asUser($admin)->get("/api/v1/admin/notifications/campaigns/{$campaign['id']}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString($second->email, $csv);
        $this->asUser($admin)->getJson('/api/v1/admin/notifications/tracking?status=read')->assertOk()->assertJsonPath('summary.read', 1);
    }

    public function test_admin_can_notify_the_trainees_of_a_program_and_the_survey_can_open_automatically(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        [$program, $trainee] = $this->enrolled();
        $this->makeSession($program, now()->addDay());

        $this->asUser($admin)->getJson("/api/v1/admin/notifications/audience?program_id={$program->id}")->assertOk()->assertJsonPath('data.count', 1);
        $sent = $this->asUser($admin)->postJson('/api/v1/admin/notifications/send', ['program_id' => $program->id, 'title_ar' => 'استبيان {{program}}', 'title_en' => 'Survey {{program}}', 'body_ar' => 'نرجو التعبئة', 'body_en' => 'Please fill it in'])->assertCreated()->json('data');
        $this->assertSame(1, $sent['recipients']);
        $this->assertSame("استبيان {$program->title_ar}", AppNotification::where('user_id', $trainee->id)->latest()->value('title_ar'));

        // Automatic: opens N hours after the program ends.
        $this->asUser($admin)->putJson("/api/v1/admin/programs/{$program->id}/survey", ['mode' => 'auto', 'auto_hours' => 1])->assertOk();
        $this->assertSame(0, app(ProgramSurvey::class)->openDue(), 'the program has not been over for long enough yet');
        $this->travel(5)->days();
        $this->assertSame(1, app(ProgramSurvey::class)->openDue());
        $this->assertTrue($program->fresh()->surveyIsOpen());
        $this->assertSame(1, AppNotification::where('user_id', $trainee->id)->where('type', 'survey.open')->count());
    }
}
