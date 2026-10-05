<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\GroupTrainer;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingKit;
use Tests\TestCase;

class TrainerAssignmentTest extends TestCase
{
    private function setup_(): array
    {
        $program = $this->makeProgram();
        $group = $program->groups()->first();
        $trainerUser = $this->makeUser(Role::TRAINER);
        $trainer = Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'user_id' => $trainerUser->id, 'status' => 'active', 'source' => 'center']);

        return [$program, $group, $trainerUser, $trainer, $this->makeUser(Role::TRAINING_HEAD)];
    }

    public function test_proposing_a_trainer_notifies_them_and_waits_for_the_form(): void
    {
        [, $group, $trainerUser, $trainer, $head] = $this->setup_();

        $res = $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/trainers", ['trainer_id' => $trainer->id, 'role' => 'lead', 'hours' => 6])->assertCreated();

        $this->assertSame('proposed', $res->json('data.status'));
        $this->assertNull($res->json('data.form_submitted_at'));
        $this->assertTrue(AppNotification::where('user_id', $trainerUser->id)->where('type', 'trainer.assignment_proposed')->exists());
    }

    public function test_the_same_trainer_cannot_be_proposed_twice_and_busy_trainers_are_refused(): void
    {
        [$program, $group, , $trainer, $head] = $this->setup_();
        $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/trainers", ['trainer_id' => $trainer->id])->assertCreated();
        $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/trainers", ['trainer_id' => $trainer->id])->assertStatus(422);

        $other = $program->groups()->create(['code' => 'X-G2', 'sequence' => 2, 'capacity' => 5, 'status' => TrainingGroup::PLANNED]);
        ProgramSession::create(['program_id' => $program->id, 'training_group_id' => $group->id, 'title_ar' => 'ج', 'title_en' => 'S', 'trainer_id' => $trainer->id, 'starts_at' => now()->addDays(3)->setTime(9, 0), 'ends_at' => now()->addDays(3)->setTime(12, 0), 'status' => 'scheduled']);
        ProgramSession::create(['program_id' => $program->id, 'training_group_id' => $other->id, 'title_ar' => 'ج', 'title_en' => 'S2', 'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(11, 0), 'status' => 'scheduled']);

        $this->asUser($head)->postJson("/api/v1/admin/groups/{$other->id}/trainers", ['trainer_id' => $trainer->id])
            ->assertStatus(422)->assertJsonPath('code', 'trainer_conflict');
    }

    public function test_approval_requires_the_form_and_the_competent_authority_reference(): void
    {
        [, $group, $trainerUser, $trainer, $head] = $this->setup_();
        $id = $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/trainers", ['trainer_id' => $trainer->id])->json('data.id');

        $this->asUser($head)->postJson("/api/v1/admin/group-trainers/{$id}/decision", ['decision' => 'approved', 'external_approval_ref' => 'MOE-2026-14'])
            ->assertStatus(422)->assertJsonPath('code', 'form_missing');

        $this->asUser($trainerUser)->putJson("/api/v1/me/assignments/{$id}/form", ['form' => ['availability_confirmed' => true, 'cv_updated' => true, 'notes' => 'ok']])->assertOk();
        $this->assertNotNull(GroupTrainer::find($id)->form_submitted_at);

        $this->asUser($head)->postJson("/api/v1/admin/group-trainers/{$id}/decision", ['decision' => 'approved'])->assertStatus(422);

        $this->asUser($head)->postJson("/api/v1/admin/group-trainers/{$id}/decision", ['decision' => 'approved', 'external_approval_ref' => 'MOE-2026-14'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue(AppNotification::where('user_id', $trainerUser->id)->where('type', 'trainer.assignment_decided')->exists());
    }

    public function test_a_trainer_only_sees_and_edits_their_own_assignments(): void
    {
        [, $group, , $trainer, $head] = $this->setup_();
        $id = $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/trainers", ['trainer_id' => $trainer->id])->json('data.id');
        $stranger = $this->makeUser(Role::TRAINER);

        $this->asUser($stranger)->putJson("/api/v1/me/assignments/{$id}/form", ['form' => ['notes' => 'x']])->assertStatus(404);
        $this->asUser($stranger)->getJson('/api/v1/me/assignments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_kit_developers_get_a_draft_kit_with_a_due_date(): void
    {
        [$program, , , , $head] = $this->setup_();
        $dev = $this->makeUser(Role::KIT_DEVELOPER);

        $res = $this->asUser($head)->postJson("/api/v1/admin/programs/{$program->id}/kit-developers", ['user_ids' => [$dev->id], 'due_at' => now()->addDays(20)->toDateString()])->assertCreated();

        $kit = TrainingKit::find($res->json('data.kit_id'));
        $this->assertSame($program->id, $kit->program_id);
        $this->assertTrue($kit->members()->where('user_id', $dev->id)->where('role', 'developer')->exists());
        $this->assertTrue(AppNotification::where('user_id', $dev->id)->where('type', 'kit.developer_assigned')->exists());

        // Re-assigning reuses the same kit.
        $again = $this->asUser($head)->postJson("/api/v1/admin/programs/{$program->id}/kit-developers", ['user_ids' => [$dev->id]])->assertCreated();
        $this->assertSame($kit->id, $again->json('data.kit_id'));
    }
}
