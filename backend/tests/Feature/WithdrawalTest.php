<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Models\WithdrawalReason;
use App\Models\WithdrawalRequest;
use App\Services\RegistrationService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    /** @return array{0: User, 1: Employee, 2: User, 3: Program} manager user, trainee, supervisor/head user, program */
    private function world(array $program = []): array
    {
        $managerUser = $this->makeUser(Role::SUPERVISOR);
        $manager = $this->makeEmployee([], $managerUser);
        $trainee = $this->makeEmployee(['supervisor_id' => $manager->id]);

        return [$managerUser, $trainee, $this->makeUser(Role::COORDINATOR), $this->makeProgram($program)];
    }

    public function test_before_the_manager_approves_and_while_registration_is_open_the_trainee_withdraws_freely_and_the_waiting_list_moves(): void
    {
        [, $trainee, , $program] = $this->world(['capacity' => 1]);
        $reg = app(RegistrationService::class)->register($program, $trainee, Registration::SOURCE_SELF);
        $waiting = app(RegistrationService::class)->register($program, $this->makeEmployee(), Registration::SOURCE_SELF);
        $this->assertSame(Registration::STATUS_WAITLISTED, $waiting->status);

        $res = $this->asUser($trainee->user)->postJson("/api/v1/me/registrations/{$reg->id}/withdraw")->assertOk();

        $this->assertSame('direct', $res->json('data.mode'));
        $this->assertSame(Registration::STATUS_WITHDRAWN, $reg->fresh()->status);
        $this->assertNotSame(Registration::STATUS_WAITLISTED, $waiting->fresh()->status);
    }

    public function test_after_the_manager_approved_withdrawing_needs_the_managers_decision(): void
    {
        [$managerUser, $trainee, , $program] = $this->world();
        $reg = app(RegistrationService::class)->register($program, $trainee, Registration::SOURCE_SELF);
        $this->asUser($managerUser)->postJson("/api/v1/admin/registrations/{$reg->id}/manager-decision", ['decision' => 'approved'])->assertOk();

        $res = $this->asUser($trainee->user)->postJson("/api/v1/me/registrations/{$reg->id}/withdraw", ['reason_code' => 'other', 'reason_text' => 'Illness'])->assertOk();
        $this->assertSame('request', $res->json('data.mode'));
        $this->assertTrue(AppNotification::where('user_id', $managerUser->id)->where('type', 'withdrawal.requested')->exists());
        $this->assertSame(Registration::STATUS_PENDING, $reg->fresh()->status, 'nothing changes until the manager decides');

        $id = $res->json('data.request.id');
        $this->asUser($managerUser)->postJson("/api/v1/admin/withdrawals/{$id}/decision", ['decision' => 'approved'])->assertOk();
        $this->assertSame(Registration::STATUS_WITHDRAWN, $reg->fresh()->status);
        $this->assertTrue(AppNotification::where('user_id', $trainee->user_id)->where('type', 'withdrawal.decided')->exists());
    }

    public function test_an_approved_seat_needs_the_manager_then_the_supervisor_and_a_rejection_keeps_the_seat(): void
    {
        [$managerUser, $trainee, $supervisor, $program] = $this->world();
        $reg = app(RegistrationService::class)->register($program, $trainee, Registration::SOURCE_CENTER, $supervisor, null, true);
        $id = $this->asUser($trainee->user)->postJson("/api/v1/me/registrations/{$reg->id}/withdraw", ['reason_code' => 'other', 'reason_text' => 'Clash'])->assertOk()->json('data.request.id');

        $this->asUser($supervisor)->postJson("/api/v1/admin/withdrawals/{$id}/decision", ['decision' => 'approved'])->assertForbidden();
        $this->asUser($managerUser)->postJson("/api/v1/admin/withdrawals/{$id}/decision", ['decision' => 'approved', 'note' => 'fine'])->assertOk()->assertJsonPath('data.stage', 'supervisor');
        $this->assertSame(Registration::STATUS_APPROVED, $reg->fresh()->status);

        $this->asUser($supervisor)->postJson("/api/v1/admin/withdrawals/{$id}/decision", ['decision' => 'rejected'])->assertStatus(422);
        $this->asUser($supervisor)->postJson("/api/v1/admin/withdrawals/{$id}/decision", ['decision' => 'rejected', 'note' => 'Seat is needed'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(Registration::STATUS_APPROVED, $reg->fresh()->status);

        $this->asUser($trainee->user)->postJson("/api/v1/me/registrations/{$reg->id}/withdraw", ['reason_code' => 'other'])->assertOk();
        $second = WithdrawalRequest::where('status', 'pending')->first();
        $this->asUser($managerUser)->postJson("/api/v1/admin/withdrawals/{$second->id}/decision", ['decision' => 'approved'])->assertOk();
        $this->asUser($supervisor)->postJson("/api/v1/admin/withdrawals/{$second->id}/decision", ['decision' => 'approved'])->assertOk();
        $this->assertSame(Registration::STATUS_WITHDRAWN, $reg->fresh()->status);
    }

    public function test_reasons_can_require_an_attachment_and_timing_and_lateness_are_recorded(): void
    {
        [, $trainee, $supervisor, $program] = $this->world(['registration_closes_at' => now()->subDay(), 'start_date' => today()->addDays(2)->toDateString(), 'end_date' => today()->addDays(4)->toDateString()]);
        WithdrawalReason::create(['code' => 'medical', 'label_ar' => 'طبي', 'label_en' => 'Medical', 'requires_attachment' => true]);
        $reg = app(RegistrationService::class)->register($program, $trainee, Registration::SOURCE_CENTER, $supervisor, null, true);
        $this->asUser($supervisor)->putJson('/api/v1/admin/settings/withdrawal-policy', ['min_days_before_start' => 5, 'allow_after_start' => true])->assertOk();

        $this->asUser($trainee->user)->postJson("/api/v1/me/registrations/{$reg->id}/withdraw", ['reason_code' => 'medical'])->assertStatus(422)->assertJsonPath('code', 'attachment_required');
        $res = $this->asUser($trainee->user)->post("/api/v1/me/registrations/{$reg->id}/withdraw", ['reason_code' => 'medical', 'attachments' => [UploadedFile::fake()->create('note.pdf', 20, 'application/pdf')]], ['Accept' => 'application/json'])->assertOk();

        $req = WithdrawalRequest::find($res->json('data.request.id'));
        $this->assertSame('before_start', $req->timing);
        $this->assertTrue($req->is_late);
        $this->assertCount(1, $req->attachments);
    }

    public function test_the_policy_and_the_reasons_list_are_managed_by_the_right_roles(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $employee = $this->makeUser(Role::EMPLOYEE);

        $this->asUser($employee)->putJson('/api/v1/admin/settings/withdrawal-policy', ['min_days_before_start' => 1, 'allow_after_start' => false])->assertForbidden();
        $this->asUser($head)->putJson('/api/v1/admin/settings/withdrawal-policy', ['min_days_before_start' => 1, 'allow_after_start' => false])->assertOk();
        $this->asUser($head)->getJson('/api/v1/admin/settings/withdrawal-policy')->assertOk()->assertJsonPath('data.allow_after_start', false);
        $this->asUser($head)->postJson('/api/v1/admin/withdrawal-reasons', ['code' => 'conference', 'label_ar' => 'مؤتمر', 'label_en' => 'Conference', 'requires_attachment' => false])->assertCreated();
        $this->asUser($employee)->getJson('/api/v1/me/withdrawal-reasons')->assertOk()->assertJsonFragment(['code' => 'conference']);
    }
}
