<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\NeedsCycle;
use App\Models\ProgramProposal;
use App\Models\Role;
use App\Models\TrainingPlan;
use Tests\TestCase;

class NeedsCycleTest extends TestCase
{
    private function planner()
    {
        return $this->makeUser(Role::PLANNING_HEAD);
    }

    private function deputy(): array
    {
        $user = $this->makeUser(Role::ACADEMIC_DEPUTY);

        return [$user, $this->makeEmployee([], $user)];
    }

    private function openCycle($planner, array $extra = []): string
    {
        $id = $this->asUser($planner)->postJson('/api/v1/admin/needs-cycles', $extra + ['year' => 2027, 'title_ar' => 'دورة ٢٠٢٧', 'title_en' => 'Cycle 2027', 'closes_at' => now()->addDays(20)->toDateTimeString()])->assertCreated()->json('data.id');
        $this->asUser($planner)->postJson("/api/v1/admin/needs-cycles/{$id}/open")->assertOk()->assertJsonPath('data.status', 'open');

        return $id;
    }

    private function proposal(array $extra = []): array
    {
        return $extra + ['program_title_ar' => 'برنامج مقترح', 'program_title_en' => 'Proposed', 'groups_count' => 2, 'days' => 3, 'hours' => 18, 'kit_availability' => 'partial', 'importance' => 4, 'justification' => 'Needed', 'axes' => ['Assessment'], 'trainer_nominations' => [['type' => 'internal', 'name' => 'A']]];
    }

    public function test_opening_a_cycle_notifies_proposers_and_submissions_are_blocked_outside_the_window(): void
    {
        $planner = $this->planner();
        [$deputy] = $this->deputy();
        $draft = $this->asUser($planner)->postJson('/api/v1/admin/needs-cycles', ['year' => 2027, 'title_ar' => 'د', 'title_en' => 'C', 'closes_at' => now()->addDays(5)->toDateTimeString()])->json('data.id');

        $this->asUser($deputy)->postJson("/api/v1/admin/needs-cycles/{$draft}/proposals", $this->proposal())->assertStatus(422)->assertJsonPath('code', 'cycle_closed');

        $this->asUser($planner)->postJson("/api/v1/admin/needs-cycles/{$draft}/open")->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $deputy->id)->where('type', 'needs.cycle_opened')->exists());
        $this->asUser($deputy)->postJson("/api/v1/admin/needs-cycles/{$draft}/proposals", $this->proposal())->assertCreated()->assertJsonPath('data.status', 'submitted');

        $this->asUser($planner)->postJson("/api/v1/admin/needs-cycles/{$draft}/close")->assertOk();
        $this->asUser($deputy)->postJson("/api/v1/admin/needs-cycles/{$draft}/proposals", $this->proposal())->assertStatus(422)->assertJsonPath('code', 'cycle_closed');
    }

    public function test_a_proposal_keeps_every_rfp_field_and_can_be_edited_only_by_its_owner_while_open(): void
    {
        $planner = $this->planner();
        [$deputy] = $this->deputy();
        [$other] = $this->deputy();
        $cycle = $this->openCycle($planner);

        $res = $this->asUser($deputy)->postJson("/api/v1/admin/needs-cycles/{$cycle}/proposals", $this->proposal())->assertCreated();
        $p = ProgramProposal::find($res->json('data.id'));
        $this->assertSame(2, $p->groups_count);
        $this->assertSame(3, $p->days);
        $this->assertSame('partial', $p->kit_availability);
        $this->assertSame(['Assessment'], $p->axes);
        $this->assertSame('school', $p->entity_type);

        $this->asUser($deputy)->putJson("/api/v1/admin/proposals/{$p->id}", ['importance' => 5])->assertOk()->assertJsonPath('data.importance', 5);
        $this->asUser($other)->putJson("/api/v1/admin/proposals/{$p->id}", ['importance' => 1])->assertForbidden();
        $this->asUser($other)->getJson("/api/v1/admin/needs-cycles/{$cycle}/proposals")->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($planner)->getJson("/api/v1/admin/needs-cycles/{$cycle}/proposals")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_review_accepts_into_the_plan_merges_or_rejects_with_a_note(): void
    {
        $planner = $this->planner();
        [$deputy] = $this->deputy();
        $plan = TrainingPlan::create(['year' => 2027, 'version' => 1, 'title_ar' => 'خ', 'title_en' => 'P', 'status' => 'draft']);
        $cycle = $this->openCycle($planner, ['plan_id' => $plan->id]);
        $id = $this->asUser($deputy)->postJson("/api/v1/admin/needs-cycles/{$cycle}/proposals", $this->proposal())->json('data.id');

        $this->asUser($planner)->postJson("/api/v1/admin/proposals/{$id}/review", ['decision' => 'rejected'])->assertStatus(422);
        $this->asUser($planner)->postJson("/api/v1/admin/proposals/{$id}/review", ['decision' => 'accepted', 'priority_rank' => 1])->assertOk()->assertJsonPath('data.status', 'accepted');

        $item = $plan->items()->first();
        $this->assertSame('Proposed', $item->title_en);
        $this->assertSame(2, $item->planned_groups);
        $this->assertEquals(36, $item->planned_hours);
        $this->assertSame($item->id, ProgramProposal::find($id)->plan_item_id);
        $this->assertTrue(AppNotification::where('user_id', $deputy->id)->where('type', 'proposal.reviewed')->exists());

        $second = $this->asUser($deputy)->postJson("/api/v1/admin/needs-cycles/{$cycle}/proposals", $this->proposal(['program_title_en' => 'Second']))->json('data.id');
        $program = $this->makeProgram();
        $this->asUser($planner)->postJson("/api/v1/admin/proposals/{$second}/review", ['decision' => 'merged'])->assertStatus(422);
        $this->asUser($planner)->postJson("/api/v1/admin/proposals/{$second}/review", ['decision' => 'merged', 'existing_program_id' => $program->id])->assertOk()->assertJsonPath('data.status', 'merged');
        $this->assertSame(1, $plan->items()->count());
    }

    public function test_manager_requests_follow_the_same_window_and_convert_to_plan_items(): void
    {
        $planner = $this->planner();
        $manager = $this->makeUser(Role::SUPERVISOR);
        $managerEmployee = $this->makeEmployee([], $manager);
        $staff = $this->makeEmployee(['supervisor_id' => $managerEmployee->id]);
        $stranger = $this->makeEmployee();
        $plan = TrainingPlan::create(['year' => 2027, 'version' => 1, 'title_ar' => 'خ', 'title_en' => 'P', 'status' => 'draft']);
        $cycle = $this->openCycle($planner, ['plan_id' => $plan->id]);

        $this->asUser($manager)->postJson('/api/v1/admin/institutional-requests', ['title' => 'Differentiation', 'need_degree' => 4, 'objectives' => ['x'], 'employee_ids' => [$staff->id, $stranger->id]])->assertStatus(422);
        $res = $this->asUser($manager)->postJson('/api/v1/admin/institutional-requests', ['title' => 'Differentiation', 'need_degree' => 4, 'objectives' => ['x'], 'employee_ids' => [$staff->id], 'preferred_window' => 'Q2'])->assertCreated();

        $this->asUser($planner)->postJson("/api/v1/admin/institutional-requests/{$res->json('data.id')}/review", ['decision' => 'accepted'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(1, $plan->items()->first()->planned_seats);
        $this->assertTrue(AppNotification::where('user_id', $manager->id)->where('type', 'request.reviewed')->exists());
        $this->assertSame($cycle, $res->json('data.cycle_id'));
    }

    public function test_the_daily_job_reminds_once_and_closes_the_cycle_at_its_deadline(): void
    {
        $planner = $this->planner();
        [$deputy] = $this->deputy();
        $soon = $this->openCycle($planner, ['closes_at' => now()->addDays(2)->toDateTimeString()]);

        $this->artisan('tedc:needs-cycles')->assertSuccessful();
        $this->artisan('tedc:needs-cycles')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $deputy->id)->where('type', 'needs.cycle_closing')->count());

        NeedsCycle::whereKey($soon)->update(['closes_at' => now()->subHour()]);
        $this->artisan('tedc:needs-cycles')->assertSuccessful();
        $this->assertSame('closed', NeedsCycle::find($soon)->status);
    }
}
