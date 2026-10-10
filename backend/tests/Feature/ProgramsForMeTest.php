<?php

namespace Tests\Feature;

use App\Models\JobTitle;
use App\Models\Program;
use App\Models\Registration;
use App\Models\TargetGroup;
use Tests\TestCase;

class ProgramsForMeTest extends TestCase
{
    private function program(string $code, array $attributes = []): Program
    {
        return $this->makeProgram(['code' => $code, 'owner_type' => 'center'] + $attributes);
    }

    public function test_the_home_slider_lists_my_unfinished_programs_with_their_delivery_mode(): void
    {
        $employee = $this->makeEmployee();
        $online = $this->program('ONL-1', ['delivery_mode' => 'online']);
        $hybrid = $this->program('HYB-1', ['delivery_mode' => 'hybrid']);
        $done = $this->program('OLD-1', ['delivery_mode' => 'in_person', 'status' => Program::STATUS_COMPLETED, 'end_date' => today()->subDay()]);
        $other = $this->program('NOT-MINE', ['delivery_mode' => 'online']);
        foreach ([$online, $hybrid, $done] as $p) {
            Registration::create(['program_id' => $p->id, 'employee_id' => $employee->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
        }
        $this->makeSession($online, now()->addDays(2));

        $rows = $this->asUser($employee->user)->getJson('/api/v1/me/home')->assertOk()->json('data.upcoming_programs');

        $this->assertEqualsCanonicalizing(['ONL-1', 'HYB-1'], array_map(fn ($r) => $r['program']['code'], $rows));
        $this->assertNotContains($other->code, array_map(fn ($r) => $r['program']['code'], $rows));
        $byCode = collect($rows)->keyBy(fn ($r) => $r['program']['code']);
        $this->assertSame('online', $byCode['ONL-1']['mode']);
        $this->assertSame('hybrid', $byCode['HYB-1']['mode']);
        $this->assertNotNull($byCode['ONL-1']['next_session_at']);
    }

    public function test_programs_for_me_shows_only_the_groups_the_person_is_in(): void
    {
        $employee = $this->makeEmployee([], null, 'TEACHER');
        $leaders = JobTitle::where('code', '!=', 'TEACHER')->firstOrFail();

        $general = $this->program('GEN-1');
        $forTeachers = $this->program('TEA-1');
        TargetGroup::create(['program_id' => $forTeachers->id, 'job_title_id' => $employee->job_title_id]);
        $forLeaders = $this->program('LEAD-1');
        TargetGroup::create(['program_id' => $forLeaders->id, 'job_title_id' => $leaders->id]);
        $draft = $this->program('DRAFT-1', ['status' => Program::STATUS_DRAFT]);
        $ended = $this->program('END-1', ['end_date' => today()->subDay()]);
        Registration::create(['program_id' => $forTeachers->id, 'employee_id' => $employee->id, 'source' => 'self', 'status' => Registration::STATUS_PENDING]);

        $data = $this->asUser($employee->user)->getJson('/api/v1/me/programs-for-me')->assertOk()->json('data');
        $codes = fn (array $rows) => array_map(fn ($p) => $p['code'], $rows);

        $this->assertSame(['TEA-1'], $codes($data['mine']));
        $this->assertSame('pending', $data['mine'][0]['my_registration']);
        $this->assertSame(['GEN-1'], $codes($data['general']));
        $everything = array_merge($codes($data['mine']), $codes($data['general']), array_map(fn ($r) => $r['program']['code'], $data['recommended']));
        foreach ([$forLeaders, $draft, $ended] as $hidden) {
            $this->assertNotContains($hidden->code, $everything, $hidden->code.' must not be offered to this person');
        }
        $this->assertContains('GEN-1', array_merge($codes($data['general']), []));
        $this->assertNotNull($general->id);
    }

    public function test_a_person_without_a_trainee_profile_gets_a_clear_refusal(): void
    {
        $this->asUser($this->makeUser())->getJson('/api/v1/me/programs-for-me')->assertNotFound();
    }
}
