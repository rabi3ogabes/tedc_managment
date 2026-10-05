<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\TrainingGroup;
use App\Models\TrainingNeed;
use App\Models\TrainingPlan;
use Tests\TestCase;

class AnnualPlanTest extends TestCase
{
    private function need(array $extra = []): TrainingNeed
    {
        return TrainingNeed::create($extra + ['school_id' => $this->makeSchool()->id, 'skill_name' => 'Active learning', 'employees_count' => 10, 'priority' => 'high', 'reason' => 'x', 'status' => 'approved']);
    }

    private function plan($head, int $year = 2027): string
    {
        return $this->asUser($head)->postJson('/api/v1/admin/plans', ['year' => $year, 'title_ar' => 'خطة', 'title_en' => 'Plan '.$year])->assertCreated()->json('data.id');
    }

    public function test_generation_ranks_needs_with_an_explanation_and_skips_duplicates(): void
    {
        $specialist = $this->makeUser(Role::PLANNING_SPECIALIST);
        $this->need(['skill_name' => 'Active learning', 'employees_count' => 40, 'priority' => 'critical']);
        $this->need(['skill_name' => 'Active learning', 'employees_count' => 10, 'priority' => 'high']);
        $this->need(['skill_name' => 'Assessment', 'employees_count' => 5, 'priority' => 'low']);
        $this->need(['skill_name' => 'Ignored', 'status' => 'rejected']);
        $id = $this->plan($specialist);

        $res = $this->asUser($specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();
        $items = $res->json('data.items');

        $this->assertCount(2, $items);
        $this->assertSame('Active learning', $items[0]['title_en']);
        $this->assertGreaterThan($items[1]['priority_score'], $items[0]['priority_score']);
        $this->assertNotEmpty($items[0]['rationale_en']);
        $this->assertSame(50, $items[0]['planned_seats']);

        $this->assertSame(0, $this->asUser($specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->json('data.added'));
    }

    public function test_the_workflow_needs_the_right_role_and_snapshots_a_baseline(): void
    {
        $specialist = $this->makeUser(Role::PLANNING_SPECIALIST);
        $head = $this->makeUser(Role::PLANNING_HEAD);
        $leader = $this->makeUser(Role::CENTER_LEADERSHIP);
        $this->need();
        $id = $this->plan($specialist);
        $this->asUser($specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();

        $this->asUser($specialist)->postJson("/api/v1/admin/plans/{$id}/approve")->assertForbidden();
        $this->asUser($specialist)->postJson("/api/v1/admin/plans/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->asUser($head)->postJson("/api/v1/admin/plans/{$id}/return", ['comment' => 'Add more'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->asUser($specialist)->postJson("/api/v1/admin/plans/{$id}/submit")->assertOk();
        $this->asUser($leader)->postJson("/api/v1/admin/plans/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $plan = TrainingPlan::find($id);
        $this->assertCount(1, $plan->baseline['items']);
        $this->assertNotNull($plan->approved_at);
        $this->assertTrue(AppNotification::where('user_id', $specialist->id)->where('type', 'plan.approved')->exists());
    }

    public function test_changes_after_approval_need_a_reason_and_are_logged(): void
    {
        $leader = $this->makeUser(Role::PLANNING_HEAD);
        $this->need();
        $id = $this->plan($leader);
        $this->asUser($leader)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();
        $this->asUser($leader)->postJson("/api/v1/admin/plans/{$id}/submit")->assertOk();
        $this->asUser($leader)->postJson("/api/v1/admin/plans/{$id}/approve")->assertOk();
        $item = TrainingPlan::find($id)->items()->first();

        $this->asUser($leader)->putJson("/api/v1/admin/plans/{$id}/items/{$item->id}", ['planned_groups' => 3])->assertStatus(422)->assertJsonPath('code', 'reason_required');
        $this->asUser($leader)->putJson("/api/v1/admin/plans/{$id}/items/{$item->id}", ['planned_groups' => 3, 'reason' => 'Demand grew'])->assertOk();

        $changes = $this->asUser($leader)->getJson("/api/v1/admin/plans/{$id}/changes")->assertOk()->json('data');
        $this->assertSame('modified', $changes[0]['change_type']);
        $this->assertSame('Demand grew', $changes[0]['reason']);
        $this->assertSame(3, $changes[0]['after']['planned_groups']);
    }

    public function test_execution_measures_progress_changes_delays_and_emergency_share(): void
    {
        $leader = $this->makeUser(Role::PLANNING_HEAD);
        $this->need();
        $id = $this->plan($leader);
        $this->asUser($leader)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();
        $this->asUser($leader)->postJson("/api/v1/admin/plans/{$id}/submit")->assertOk();
        $this->asUser($leader)->postJson("/api/v1/admin/plans/{$id}/approve")->assertOk();
        $this->asUser($leader)->postJson("/api/v1/admin/plans/{$id}/activate")->assertOk();
        $plan = TrainingPlan::find($id);
        $item = $plan->items()->first();
        $item->update(['planned_groups' => 2, 'window_start' => '2020-01-01', 'window_end' => '2020-03-31']);

        $program = $this->makeProgram(['capacity' => 10]);
        $first = $program->groups()->first();
        $first->update(['plan_item_id' => $item->id, 'status' => TrainingGroup::COMPLETED]);
        $program->groups()->create(['code' => 'E-G2', 'sequence' => 2, 'capacity' => 10, 'status' => TrainingGroup::PLANNED, 'is_emergency' => true, 'start_date' => '2027-02-01']);

        $exec = $this->asUser($leader)->getJson("/api/v1/admin/plans/{$id}/execution")->assertOk()->json('data');
        $this->assertSame(1, $exec['totals']['executed_groups']);
        $this->assertSame(2, $exec['totals']['planned_groups']);
        $this->assertEquals(50, $exec['totals']['execution_percent']);
        $this->assertSame(1, $exec['totals']['emergency_groups']);
        $this->assertContains('late', array_column($exec['deviations'], 'type'));
    }

    public function test_only_one_plan_per_year_and_version_and_export_works(): void
    {
        $head = $this->makeUser(Role::PLANNING_HEAD);
        $this->need();
        $id = $this->plan($head);
        $this->asUser($head)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();
        $this->asUser($head)->postJson('/api/v1/admin/plans', ['year' => 2027, 'title_ar' => 'خطة', 'title_en' => 'Dup'])->assertStatus(422);

        $this->asUser($head)->get("/api/v1/admin/plans/{$id}/export?format=xlsx")->assertOk();
        $this->asUser($head)->get("/api/v1/admin/plans/{$id}/export?format=pdf")->assertOk();
    }
}
