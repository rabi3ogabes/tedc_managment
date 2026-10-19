<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\PdActivity;
use App\Models\PdActivityType;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\School;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\AnnualHoursService;
use Carbon\Carbon;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** Adding trainees to a group by employee number or from a school's staff: target audience only, hours of last year beside everyone. */
class GroupTraineesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** @return array{0: User, 1: Program, 2: TrainingGroup, 3: TrainingGroup, 4: School, 5: array<string, Employee>} */
    private function world(): array
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $teacher = JobTitle::where('code', 'TEACHER')->value('id');
        // A programme for teachers only; created as a draft, so its registration window is closed: the centre assigns directly.
        $id = $this->asUser($admin)->postJson('/api/v1/admin/programs', ['title_ar' => 'للمعلمين', 'total_hours' => 6, 'capacity' => 3, 'start_date' => today()->addDays(20)->toDateString(), 'end_date' => today()->addDays(21)->toDateString(),
            'target_groups' => [['job_title_id' => $teacher]], 'groups' => [['title_ar' => 'معلمون 1'], ['title_ar' => 'معلمون 2', 'capacity' => 1]]])->assertCreated()->json('data.id');
        $program = Program::findOrFail($id);
        [$g1, $g2] = $program->groups()->orderBy('sequence')->get()->all();
        $school = $this->makeSchool();
        $mk = fn (string $code, string $name) => tap($this->makeEmployee(['school_id' => $school->id], null, $code), fn ($e) => $e->user->update(['name_ar' => $name]));
        $staff = ['t1' => $mk('TEACHER', 'معلم أول'), 't2' => $mk('TEACHER', 'معلم ثانٍ'), 't3' => $mk('TEACHER', 'معلم ثالث'), 'p' => $mk('PRINCIPAL', 'مدير المدرسة')];

        return [$admin, $program, $g1, $g2, $school, $staff];
    }

    public function test_the_school_list_shows_only_the_target_audience_with_their_hours_of_last_year(): void
    {
        [$admin, $program, $g1, , $school, $s] = $this->world();
        // The first teacher trained 12 hours last year (a certificate), 5 more in an approved outside activity; this year's hours do not count.
        $year = app(AnnualHoursService::class)->yearOf() - 1;
        $when = Carbon::create($year, 6, 15);
        $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $s['t1']->id, 'status' => 'cancelled', 'source' => 'self']);
        Certificate::create(['certificate_no' => 'L1', 'verification_code' => 'L1', 'registration_id' => $reg->id, 'employee_id' => $s['t1']->id, 'program_id' => $program->id, 'issued_at' => $when, 'hours' => 12, 'status' => 'valid']);
        $type = PdActivityType::first() ?? PdActivityType::create(['code' => 'X', 'name_ar' => 'نشاط', 'name_en' => 'Activity', 'hour_rules' => []]);
        PdActivity::create(['employee_id' => $s['t1']->id, 'type_id' => $type->id, 'title' => 'this year', 'starts_on' => today(), 'duration_hours' => 40, 'computed_hours' => 40, 'approved_hours' => 40, 'status' => 'approved']);
        PdActivity::create(['employee_id' => $s['t1']->id, 'type_id' => $type->id, 'title' => 'x', 'starts_on' => $when, 'duration_hours' => 5, 'computed_hours' => 5, 'approved_hours' => 5, 'status' => 'approved']);

        $res = $this->asUser($admin)->getJson("/api/v1/admin/groups/{$g1->id}/trainee-candidates?school_id={$school->id}")->assertOk()->json();
        $names = array_column($res['data'], 'name');
        $this->assertEqualsCanonicalizing(['معلم أول', 'معلم ثانٍ', 'معلم ثالث'], $names);   // the principal is not in the target audience
        $this->assertSame(['staff' => 4, 'in_audience' => 3, 'outside_audience' => 1, 'already_registered' => 0], array_intersect_key($res['meta'], array_flip(['staff', 'in_audience', 'outside_audience', 'already_registered'])));
        $first = collect($res['data'])->firstWhere('name', 'معلم أول');
        $this->assertSame(17.0, (float) $first['hours_last_year']);
        $this->assertSame($year, $first['hours_year']);
        $this->assertSame(0.0, (float) collect($res['data'])->firstWhere('name', 'معلم ثانٍ')['hours_last_year']);

        // The same hours for any list on the page.
        $h = $this->asUser($admin)->postJson('/api/v1/admin/employees/hours-last-year', ['ids' => [$s['t1']->id, $s['t2']->id]])->assertOk()->json();
        $this->assertSame([17.0, 0.0], [(float) $h['data'][$s['t1']->id], (float) $h['data'][$s['t2']->id]]);
        $this->asUser($admin)->getJson("/api/v1/admin/groups/{$g1->id}/trainee-candidates")->assertStatus(422);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson("/api/v1/admin/groups/{$g1->id}/trainee-candidates?school_id={$school->id}")->assertForbidden();
    }

    public function test_adding_by_employee_number_checks_the_audience_and_nobody_outside_it_gets_in(): void
    {
        [$admin, $program, $g1, $g2, , $s] = $this->world();
        $nos = [$s['t1']->employee_no, $s['p']->employee_no, 'NO-SUCH-1'];
        $rows = collect($this->asUser($admin)->postJson("/api/v1/admin/groups/{$g1->id}/trainees/check", ['employee_numbers' => $nos])->assertOk()->json('data'))->keyBy('employee_no');
        $this->assertTrue($rows[$s['t1']->employee_no]['can_add']);
        $this->assertSame('معلم أول', $rows[$s['t1']->employee_no]['name']);
        $this->assertFalse($rows[$s['p']->employee_no]['can_add']);
        $this->assertSame('not_in_target_audience', $rows[$s['p']->employee_no]['reason']);
        $this->assertTrue($rows[$s['p']->employee_no]['found']);
        $this->assertNotEmpty($rows[$s['p']->employee_no]['failures']);
        $this->assertFalse($rows['NO-SUCH-1']['found']);

        // Even when asked directly (and with an override flag), the principal is refused; the teachers go in, in the group asked for.
        $res = collect($this->asUser($admin)->postJson("/api/v1/admin/groups/{$g1->id}/trainees", ['employee_ids' => [$s['t1']->id, $s['p']->id, $s['t2']->id], 'override' => true])->assertCreated()->json('data'))->keyBy('employee_id');
        $this->assertTrue($res[$s['t1']->id]['ok']);
        $this->assertTrue($res[$s['t2']->id]['ok']);
        $this->assertFalse($res[$s['p']->id]['ok']);
        $this->assertSame('not_in_target_audience', $res[$s['p']->id]['reason']);
        $this->assertSame(0, Registration::where('program_id', $program->id)->where('employee_id', $s['p']->id)->count());
        $regs = Registration::where('program_id', $program->id)->whereIn('employee_id', [$s['t1']->id, $s['t2']->id])->get();
        $this->assertSame([$g1->id, $g1->id], $regs->pluck('training_group_id')->all());
        $this->assertSame(['approved', 'approved'], $regs->pluck('status')->all());

        // Already in a group: said so (and the other group is named); a full group refuses; a person in the list is no longer offered.
        $again = $this->asUser($admin)->postJson("/api/v1/admin/groups/{$g2->id}/trainees/check", ['employee_numbers' => [$s['t1']->employee_no]])->json('data.0');
        $this->assertSame('registered_elsewhere', $again['reason']);
        $this->assertStringContainsString('معلمون 1', $again['message']);
        $this->assertSame('already_in_group', $this->asUser($admin)->postJson("/api/v1/admin/groups/{$g1->id}/trainees/check", ['employee_numbers' => [$s['t1']->employee_no]])->json('data.0.reason'));
        $this->asUser($admin)->postJson("/api/v1/admin/groups/{$g2->id}/trainees", ['employee_ids' => [$s['t3']->id]])->assertCreated()->assertJsonPath('data.0.ok', true);
        $full = $this->asUser($admin)->postJson("/api/v1/admin/groups/{$g2->id}/trainees/check", ['employee_numbers' => [$s['t3']->employee_no]])->json('data.0');
        $this->assertSame('already_in_group', $full['reason']);
        $list = $this->asUser($admin)->getJson("/api/v1/admin/groups/{$g1->id}/trainee-candidates?school_id={$s['t1']->school_id}")->json();
        $this->assertSame(0, $list['meta']['in_audience']);
        $this->assertSame(3, $list['meta']['already_registered']);
    }

    public function test_a_school_administrator_adds_only_their_own_schools_staff_and_the_window_still_applies_to_them(): void
    {
        [$admin, $program, $g1, , $school, $s] = $this->world();
        $head = $this->makeUser(Role::SCHOOL_ADMIN);
        $this->makeEmployee(['school_id' => $school->id], $head);
        $otherSchool = $this->makeSchool();
        $stranger = $this->makeEmployee(['school_id' => $otherSchool->id], null, 'TEACHER');

        // The programme is a draft: registration is closed, so the school administrator cannot add yet (the centre can).
        $r = $this->asUser($head)->postJson("/api/v1/admin/groups/{$g1->id}/trainees", ['employee_ids' => [$s['t1']->id, $stranger->id]])->assertCreated()->json('data');
        $this->assertFalse(collect($r)->firstWhere('employee_id', $s['t1']->id)['ok']);
        $this->assertFalse(collect($r)->firstWhere('employee_id', $stranger->id)['ok']);
        $this->asUser($head)->getJson("/api/v1/admin/groups/{$g1->id}/trainee-candidates?school_id={$otherSchool->id}")->assertForbidden();
        $program->update(['status' => 'registration_open']);
        $g1->update(['status' => 'registration_open', 'published_at' => now(), 'registration_opens_at' => now()->subDay(), 'registration_closes_at' => now()->addDays(5)]);
        $ok = $this->asUser($head)->postJson("/api/v1/admin/groups/{$g1->id}/trainees", ['employee_ids' => [$s['t1']->id]])->assertCreated()->json('data.0');
        $this->assertTrue($ok['ok'], json_encode($ok, JSON_UNESCAPED_UNICODE));
    }
}
