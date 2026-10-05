<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\Role;
use App\Services\RegistrationService;
use Tests\TestCase;

class AdmissionToolsTest extends TestCase
{
    private function applicants(int $capacity, int $n): array
    {
        $program = $this->makeProgram(['capacity' => $capacity]);
        $regs = [];
        foreach (range(1, $n) as $i) {
            $regs[] = app(RegistrationService::class)->register($program, $this->makeEmployee(), Registration::SOURCE_SELF);
        }

        return [$program, $program->groups()->first(), $regs];
    }

    public function test_the_candidate_list_ranks_applicants_with_their_history_and_bulk_acceptance_stops_at_the_seats(): void
    {
        [, $group, $regs] = $this->applicants(2, 3);
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $list = $this->asUser($head)->getJson("/api/v1/admin/groups/{$group->id}/candidates")->assertOk()->json('data');
        $this->assertCount(3, $list);
        $this->assertArrayHasKey('completed_12_months', $list[0]['history']);

        $res = $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/accept", ['ids' => array_map(fn ($r) => $r->id, $regs)])->assertOk();
        $this->assertSame(2, $res->json('data.approved'));
        $this->assertSame('no_seats', $res->json('data.skipped.0.reason'));
    }

    public function test_priority_rules_can_be_previewed_before_saving_and_equivalences_are_edited_per_program(): void
    {
        [$program, $group] = $this->applicants(5, 2);
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $preview = $this->asUser($head)->postJson('/api/v1/admin/priority-rules/preview', ['group_id' => $group->id, 'criteria' => ['no_training_in_months'], 'weights' => ['no_training_in_months' => 50]])->assertOk()->json('data');
        $this->assertCount(2, $preview);
        $this->assertEquals(50, $preview[0]['score']);

        $this->asUser($head)->postJson('/api/v1/admin/priority-rules', ['scope' => 'global', 'criteria' => ['no_training_in_months', 'registration_date'], 'weights' => ['no_training_in_months' => 40]])->assertCreated();
        $other = $this->makeProgram();
        $this->asUser($head)->putJson("/api/v1/admin/programs/{$program->id}/equivalences", ['repeat_policy' => 'warn', 'equivalents' => [['program_id' => $other->id, 'bidirectional' => true]]])->assertOk()->assertJsonPath('data.repeat_policy', 'warn')->assertJsonCount(1, 'data.equivalents');
    }

    public function test_the_approval_queues_show_what_waits_for_the_manager_and_for_the_centre(): void
    {
        $managerUser = $this->makeUser(Role::SUPERVISOR);
        $manager = $this->makeEmployee([], $managerUser);
        $program = $this->makeProgram();
        app(RegistrationService::class)->register($program, $this->makeEmployee(['supervisor_id' => $manager->id]), Registration::SOURCE_SELF);
        app(RegistrationService::class)->register($program, $this->makeEmployee(), Registration::SOURCE_SELF);

        $this->asUser($managerUser)->getJson('/api/v1/admin/approvals/manager')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->makeUser(Role::COORDINATOR))->getJson('/api/v1/admin/approvals/center')->assertOk()->assertJsonCount(1, 'data');
    }
}
