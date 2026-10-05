<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\IndividualNeed;
use App\Models\Program;
use App\Models\ProgramEquivalence;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\School;
use App\Models\Skill;
use App\Services\RegistrationService;
use App\Services\SeatAllocationService;
use Tests\TestCase;

class EnrollmentEngineTest extends TestCase
{
    private function svc(): RegistrationService
    {
        return app(RegistrationService::class);
    }

    private function emp(?School $school = null, array $extra = []): Employee
    {
        return $this->makeEmployee($extra + ($school ? ['school_id' => $school->id] : []));
    }

    private function sessionAt(Program $p, string $start, int $hours = 2): ProgramSession
    {
        return ProgramSession::create(['program_id' => $p->id, 'training_group_id' => $p->groups()->first()->id, 'title_ar' => 'ج', 'title_en' => 'S', 'starts_at' => $start, 'ends_at' => date('Y-m-d H:i:s', strtotime($start) + $hours * 3600), 'status' => 'scheduled']);
    }

    public function test_seats_are_split_per_school_with_a_fallback_to_the_open_pool_and_never_exceed_capacity(): void
    {
        $a = $this->makeSchool();
        $b = $this->makeSchool();
        $program = $this->makeProgram(['capacity' => 4]);
        $group = $program->groups()->first();
        app(SeatAllocationService::class)->replace($group, [['entity_type' => 'school', 'entity_id' => $a->id, 'seats' => 2]]);

        $regs = [];
        foreach ([$a, $a, $a, $b, $b] as $school) {
            $regs[] = $this->svc()->register($program, $this->emp($school), Registration::SOURCE_CENTER, null, null, true);
        }

        $this->assertSame(['school', 'school', 'open', 'open', null], array_map(fn ($r) => $r->seat_entity_type, $regs));
        $this->assertSame(Registration::STATUS_WAITLISTED, $regs[4]->status);
        $this->assertSame(4, Registration::where('program_id', $program->id)->whereIn('status', Registration::SEAT_HOLDING)->count());
        $summary = app(SeatAllocationService::class)->summary($group);
        $this->assertSame(2, $summary['pools'][0]['taken']);
        $this->assertSame(2, $summary['open']['taken']);
    }

    public function test_the_open_pool_cannot_be_overallocated(): void
    {
        $program = $this->makeProgram(['capacity' => 3]);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $school = $this->makeSchool();
        $group = $program->groups()->first();

        $this->asUser($head)->putJson("/api/v1/admin/groups/{$group->id}/seats", ['allocations' => [['entity_type' => 'school', 'entity_id' => $school->id, 'seats' => 5]]])->assertStatus(422)->assertJsonPath('code', 'seats_over_capacity');
        $this->asUser($head)->putJson("/api/v1/admin/groups/{$group->id}/seats", ['allocations' => [['entity_type' => 'school', 'entity_id' => $school->id, 'seats' => 2]]])->assertOk()->assertJsonPath('data.open.seats', 1);
    }

    public function test_unused_allocated_seats_move_to_the_open_pool_at_release_time_and_waiting_people_are_promoted(): void
    {
        $a = $this->makeSchool();
        $b = $this->makeSchool();
        $program = $this->makeProgram(['capacity' => 3]);
        $group = $program->groups()->first();
        app(SeatAllocationService::class)->replace($group, [['entity_type' => 'school', 'entity_id' => $a->id, 'seats' => 2, 'release_at' => now()->subMinute()->toDateTimeString()]]);
        $this->svc()->register($program, $this->emp($b), Registration::SOURCE_CENTER, null, null, true);
        $waiting = $this->svc()->register($program, $this->emp($b), Registration::SOURCE_CENTER, null, null, true);
        $this->assertSame(Registration::STATUS_WAITLISTED, $waiting->status);

        $this->artisan('tedc:seats-release')->assertSuccessful();

        $this->assertSame(Registration::STATUS_PENDING, $waiting->fresh()->status, 'the freed seats let the waiting person in');
        $this->assertSame('open', $waiting->fresh()->seat_entity_type);
    }

    public function test_the_waiting_list_is_promoted_by_priority_with_an_explanation(): void
    {
        $program = $this->makeProgram(['capacity' => 1]);
        $skill = Skill::create(['code' => 'sk', 'name_en' => 'Sk', 'name_ar' => 'م', 'category' => 'x']);
        $program->skills()->attach($skill->id, ['target_level' => 3]);
        $holder = $this->svc()->register($program, $this->emp(), Registration::SOURCE_CENTER, null, null, true);
        $first = $this->svc()->register($program, $this->emp(), Registration::SOURCE_SELF);
        $needy = $this->emp();
        IndividualNeed::create(['employee_id' => $needy->id, 'skill_id' => $skill->id, 'source' => 'self', 'status' => 'approved']);
        $second = $this->svc()->register($program, $needy, Registration::SOURCE_SELF);
        $this->assertSame(Registration::STATUS_WAITLISTED, $first->status);
        $this->assertSame(Registration::STATUS_WAITLISTED, $second->status);

        $this->svc()->transition($holder->fresh(), Registration::STATUS_CANCELLED);

        $this->assertSame(Registration::STATUS_WAITLISTED, $first->fresh()->status);
        $this->assertNotSame(Registration::STATUS_WAITLISTED, $second->fresh()->status, 'the employee with an approved need goes first');
        $this->assertNotEmpty($second->fresh()->priority_explanation);
        $this->assertGreaterThan((float) $first->fresh()->priority_score, (float) $second->fresh()->priority_score);
    }

    public function test_completed_or_equivalent_programs_are_blocked_warned_or_allowed_by_policy_and_can_be_overridden(): void
    {
        $original = $this->makeProgram();
        $copy = $this->makeProgram();
        ProgramEquivalence::create(['program_id' => $copy->id, 'equivalent_program_id' => $original->id, 'bidirectional' => true]);
        $employee = $this->emp();
        Registration::create(['program_id' => $original->id, 'employee_id' => $employee->id, 'source' => 'self', 'status' => Registration::STATUS_COMPLETED]);

        $this->expectBusinessRule(fn () => $this->svc()->register($copy, $employee, Registration::SOURCE_SELF), 'already_completed');

        $copy->update(['repeat_policy' => 'warn']);
        $reg = $this->svc()->register($copy->fresh(), $employee, Registration::SOURCE_SELF);
        $this->assertNotEmpty($reg->eligibility_snapshot['repeat_warning'] ?? null);

        $other = $this->makeProgram();
        ProgramEquivalence::create(['program_id' => $other->id, 'equivalent_program_id' => $original->id, 'bidirectional' => false]);
        $this->expectBusinessRule(fn () => $this->svc()->register($other, $employee, Registration::SOURCE_SELF), 'already_completed');
        $this->assertNotNull($this->svc()->register($other->fresh(), $employee, Registration::SOURCE_CENTER, null, null, true));
    }

    public function test_time_clashes_block_the_second_approval_but_not_registration_while_neither_is_approved(): void
    {
        $employee = $this->emp();
        $p1 = $this->makeProgram();
        $p2 = $this->makeProgram();
        $this->sessionAt($p1, '2027-03-01 09:00:00');
        $this->sessionAt($p2, '2027-03-01 10:00:00');

        $r1 = $this->svc()->register($p1, $employee, Registration::SOURCE_SELF);
        $r2 = $this->svc()->register($p2, $employee, Registration::SOURCE_SELF);
        $this->assertSame(Registration::STATUS_PENDING, $r2->status);

        $this->svc()->transition($r1, Registration::STATUS_APPROVED);
        $this->expectBusinessRule(fn () => $this->svc()->transition($r2->fresh(), Registration::STATUS_APPROVED), 'time_conflict');

        $p3 = $this->makeProgram();
        $this->sessionAt($p3, '2027-03-01 09:30:00');
        $this->expectBusinessRule(fn () => $this->svc()->register($p3, $employee, Registration::SOURCE_SELF), 'time_conflict');

        $p3->groups()->first()->update(['allow_overlap_until_approved' => false]);
        $p4 = $this->makeProgram();
        $this->sessionAt($p4, '2027-03-01 10:30:00');
        $p4->groups()->first()->update(['allow_overlap_until_approved' => false]);
        $this->expectBusinessRule(fn () => $this->svc()->register($p4, $employee, Registration::SOURCE_SELF), 'time_conflict');
    }

    public function test_self_registration_goes_to_the_direct_manager_then_the_centre_and_the_centre_waits_for_the_window_to_close(): void
    {
        $managerUser = $this->makeUser(Role::SUPERVISOR);
        $manager = $this->makeEmployee([], $managerUser);
        $report = $this->emp(null, ['supervisor_id' => $manager->id]);
        $program = $this->makeProgram(['registration_closes_at' => now()->addDays(5)]);
        $head = $this->makeUser(Role::COORDINATOR);

        $reg = $this->svc()->register($program, $report, Registration::SOURCE_SELF);
        $this->assertSame(Registration::STATUS_PENDING_MANAGER, $reg->status);
        $this->assertSame($managerUser->id, $reg->manager_id);
        $this->assertTrue(AppNotification::where('user_id', $managerUser->id)->where('type', 'registration.pending_manager')->exists());

        $this->asUser($report->user)->postJson("/api/v1/admin/registrations/{$reg->id}/manager-decision", ['decision' => 'approved'])->assertForbidden();
        $this->asUser($managerUser)->postJson("/api/v1/admin/registrations/{$reg->id}/manager-decision", ['decision' => 'approved', 'note' => 'ok'])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertTrue(AppNotification::where('type', 'registration.new_pending')->exists());

        $this->asUser($head)->patchJson("/api/v1/admin/registrations/{$reg->id}/status", ['status' => 'approved'])->assertStatus(422)->assertJsonPath('code', 'window_open');
        $this->asUser($head)->patchJson("/api/v1/admin/registrations/{$reg->id}/status", ['status' => 'approved', 'override_reason' => 'Urgent'])->assertOk();
        $this->assertSame($head->id, $reg->fresh()->center_decided_by);
    }

    public function test_a_manager_can_reject_and_a_manager_registration_skips_the_manager_stage(): void
    {
        $managerUser = $this->makeUser(Role::SUPERVISOR);
        $manager = $this->makeEmployee([], $managerUser);
        $report = $this->emp(null, ['supervisor_id' => $manager->id]);
        $program = $this->makeProgram();

        $reg = $this->svc()->register($program, $report, Registration::SOURCE_SELF);
        $this->asUser($managerUser)->postJson("/api/v1/admin/registrations/{$reg->id}/manager-decision", ['decision' => 'rejected'])->assertStatus(422);
        $this->asUser($managerUser)->postJson("/api/v1/admin/registrations/{$reg->id}/manager-decision", ['decision' => 'rejected', 'note' => 'Busy season'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $program2 = $this->makeProgram();
        $viaManager = $this->svc()->register($program2, $report, Registration::SOURCE_SCHOOL);
        $this->assertSame(Registration::STATUS_PENDING, $viaManager->status);
        $this->assertSame(Registration::STATUS_PENDING, $this->svc()->register($this->makeProgram(), $this->emp(), Registration::SOURCE_SELF)->status, 'no manager found → straight to the centre');
    }

    public function test_the_approval_mode_can_skip_stages(): void
    {
        $managerUser = $this->makeUser(Role::SUPERVISOR);
        $manager = $this->makeEmployee([], $managerUser);
        $program = $this->makeProgram();
        $program->groups()->first()->update(['approval_mode' => 'center_only']);
        $this->assertSame(Registration::STATUS_PENDING, $this->svc()->register($program->fresh(), $this->emp(null, ['supervisor_id' => $manager->id]), Registration::SOURCE_SELF)->status);

        $auto = $this->makeProgram();
        $auto->groups()->first()->update(['approval_mode' => 'auto']);
        $this->assertSame(Registration::STATUS_APPROVED, $this->svc()->register($auto->fresh(), $this->emp(), Registration::SOURCE_SELF)->status);
    }

    private function expectBusinessRule(callable $fn, string $code): void
    {
        try {
            $fn();
            $this->fail("Expected business rule {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
