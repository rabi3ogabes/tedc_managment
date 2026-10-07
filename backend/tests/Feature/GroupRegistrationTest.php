<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Registration;
use App\Models\TrainingGroup;
use App\Models\WaitingList;
use Tests\TestCase;

class GroupRegistrationTest extends TestCase
{
    private function programWithTwoGroups(int $capacityA = 1, int $capacityB = 5): array
    {
        $program = $this->makeProgram(['capacity' => 99, 'status' => Program::STATUS_REGISTRATION_OPEN, 'registration_opens_at' => null, 'registration_closes_at' => null]);
        $a = $program->groups()->first();
        $a->update(['capacity' => $capacityA, 'status' => TrainingGroup::REGISTRATION_OPEN, 'published_at' => now()]);
        $b = TrainingGroup::create(['program_id' => $program->id, 'code' => $program->code.'-G2', 'sequence' => 2, 'capacity' => $capacityB, 'status' => TrainingGroup::REGISTRATION_OPEN, 'published_at' => now()]);

        return [$program->fresh(), $a, $b];
    }

    public function test_a_trainee_registers_in_the_chosen_group_and_seats_are_counted_per_group(): void
    {
        [$program, $a, $b] = $this->programWithTwoGroups(1, 5);
        $first = $this->makeEmployee();
        $second = $this->makeEmployee();

        $res = $this->asUser($first->user)->postJson("/api/v1/me/programs/{$program->id}/register", ['group_id' => $a->id])->assertCreated();
        $this->assertSame($a->id, $res->json('data.group.id'));
        $this->assertSame('pending', $res->json('data.status'));

        // The first group has one seat only: the next person lands on its waiting list, while the second group still has room.
        $this->asUser($second->user)->postJson("/api/v1/me/programs/{$program->id}/register", ['group_id' => $a->id])->assertCreated()->assertJsonPath('data.status', 'waitlisted');
        $this->assertSame(1, WaitingList::where('training_group_id', $a->id)->count());

        $third = $this->makeEmployee();
        $this->asUser($third->user)->postJson("/api/v1/me/programs/{$program->id}/register", ['group_id' => $b->id])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertSame(1, $b->seatsTaken());
    }

    public function test_without_a_choice_the_first_open_group_is_used_and_a_closed_group_is_refused(): void
    {
        [$program, $a, $b] = $this->programWithTwoGroups();
        $a->update(['status' => TrainingGroup::ONGOING]);
        $employee = $this->makeEmployee();

        $this->asUser($employee->user)->postJson("/api/v1/me/programs/{$program->id}/register")->assertCreated()->assertJsonPath('data.group.id', $b->id);

        $other = $this->makeEmployee();
        $this->asUser($other->user)->postJson("/api/v1/me/programs/{$program->id}/register", ['group_id' => $a->id])->assertUnprocessable()->assertJsonPath('code', 'registration_closed');
    }

    public function test_a_group_of_another_program_is_refused(): void
    {
        [$program] = $this->programWithTwoGroups();
        [, $foreign] = $this->programWithTwoGroups();

        $this->asUser($this->makeEmployee()->user)->postJson("/api/v1/me/programs/{$program->id}/register", ['group_id' => $foreign->id])->assertUnprocessable();
    }

    public function test_cancelling_a_seat_promotes_the_next_person_of_the_same_group(): void
    {
        [$program, $a, $b] = $this->programWithTwoGroups(1, 1);
        $holder = $this->makeEmployee();
        $waiting = $this->makeEmployee();
        $other = $this->makeEmployee();
        $this->asUser($holder->user)->postJson("/api/v1/me/programs/{$program->id}/register", ['group_id' => $a->id])->assertCreated();
        $this->asUser($waiting->user)->postJson("/api/v1/me/programs/{$program->id}/register", ['group_id' => $a->id])->assertJsonPath('data.status', 'waitlisted');
        $this->asUser($other->user)->postJson("/api/v1/me/programs/{$program->id}/register", ['group_id' => $b->id])->assertCreated();
        $this->asUser($this->makeEmployee()->user)->postJson("/api/v1/me/programs/{$program->id}/register", ['group_id' => $b->id])->assertJsonPath('data.status', 'waitlisted');

        $registration = Registration::where('employee_id', $holder->id)->first();
        $this->asUser($holder->user)->postJson("/api/v1/me/registrations/{$registration->id}/withdraw")->assertOk();

        $this->assertSame('pending', Registration::where('employee_id', $waiting->id)->value('status'), 'the waiting person of group A moved up');
        $this->assertSame(1, Registration::where('training_group_id', $b->id)->where('status', 'waitlisted')->count(), 'group B is untouched');
    }

    public function test_the_public_catalogue_shows_main_programs_with_sub_programs_units_and_open_groups(): void
    {
        [$program, $a, $b] = $this->programWithTwoGroups();
        $b->update(['status' => TrainingGroup::COMPLETED]);
        $sub = Program::create(['code' => $program->code.'-S1', 'title_ar' => 'فرعي', 'title_en' => 'Sub', 'total_hours' => 4, 'capacity' => 10, 'status' => Program::STATUS_REGISTRATION_OPEN, 'parent_id' => $program->id, 'kind' => 'sub']);
        $program->units()->create(['title_ar' => 'وحدة', 'title_en' => 'Unit', 'hours' => 2, 'sort_order' => 0]);

        $list = collect($this->getJson('/api/v1/public/programs?per_page=50')->assertOk()->json('data'))->pluck('code');
        $this->assertContains($program->code, $list);
        $this->assertNotContains($sub->code, $list, 'sub-programs are listed under their main program, not on their own');

        $detail = $this->getJson("/api/v1/public/programs/{$program->code}")->assertOk()->json('data');
        $this->assertSame([$a->code], collect($detail['groups'])->pluck('code')->all(), 'only open groups are offered');
        $this->assertSame([$sub->code], collect($detail['children'])->pluck('code')->all());
        $this->assertSame(['Unit'], collect($detail['units'])->pluck('title_en')->all());
    }
}
