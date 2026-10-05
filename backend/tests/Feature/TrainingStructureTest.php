<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Skill;
use App\Models\TrainingGroup;
use App\Services\CertificateService;
use ReflectionMethod;
use Tests\TestCase;

class TrainingStructureTest extends TestCase
{
    private function admin()
    {
        return $this->makeUser(Role::SUPER_ADMIN);
    }

    public function test_every_program_gets_a_first_group_and_its_children_point_at_it(): void
    {
        $program = $this->makeProgram(['code' => 'ABC-1', 'capacity' => 25, 'status' => Program::STATUS_REGISTRATION_OPEN, 'delivery_mode' => 'hybrid']);
        $group = $program->groups()->first();

        $this->assertSame('ABC-1-G1', $group->code);
        $this->assertSame(25, $group->capacity);
        $this->assertSame(TrainingGroup::REGISTRATION_OPEN, $group->status);
        $this->assertSame('blended', $group->delivery_mode);

        $session = $this->makeSession($program, now()->addDay());
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
        $this->assertSame($group->id, $session->training_group_id);
        $this->assertSame($group->id, $registration->training_group_id);

        $attendance = Attendance::create(['program_session_id' => $session->id, 'registration_id' => $registration->id, 'employee_id' => $employee->id, 'status' => 'present', 'method' => 'manual']);
        $this->assertSame($group->id, $attendance->training_group_id);
    }

    public function test_editing_a_single_group_program_keeps_its_group_in_step(): void
    {
        $program = $this->makeProgram(['status' => Program::STATUS_DRAFT]);
        $program->update(['capacity' => 80, 'status' => Program::STATUS_IN_PROGRESS, 'start_date' => today()]);

        $group = $program->groups()->first();
        $this->assertSame(80, $group->capacity);
        $this->assertSame(TrainingGroup::ONGOING, $group->status);
    }

    public function test_existing_programs_are_given_one_group_by_the_migration_backfill(): void
    {
        $program = $this->makeProgram(['status' => Program::STATUS_COMPLETED, 'capacity' => 40]);
        $session = $this->makeSession($program, now()->subDays(3));
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'self', 'status' => Registration::STATUS_COMPLETED]);

        // As on a database from before groups existed: no groups, rows without a group.
        \DB::table('program_sessions')->update(['training_group_id' => null]);
        \DB::table('registrations')->update(['training_group_id' => null]);
        \DB::table('training_groups')->delete();

        $migration = require base_path('database/migrations/2026_01_01_003600_training_structure_groups_and_plans.php');
        (new ReflectionMethod($migration, 'backfill'))->invoke($migration);

        $group = TrainingGroup::where('program_id', $program->id)->first();
        $this->assertNotNull($group);
        $this->assertSame(TrainingGroup::COMPLETED, $group->status);
        $this->assertSame(40, $group->capacity);
        $this->assertSame($group->id, $session->fresh()->training_group_id);
        $this->assertSame($group->id, $registration->fresh()->training_group_id);
    }

    public function test_a_sub_program_inherits_from_its_main_program_and_only_one_level_is_allowed(): void
    {
        $skill = Skill::first();
        $main = $this->makeProgram(['code' => 'MAIN-1', 'total_hours' => 30]);
        $main->skills()->attach($skill->id, ['target_level' => 3]);
        $admin = $this->admin();

        $res = $this->asUser($admin)->postJson("/api/v1/admin/programs/{$main->id}/sub-programs", ['title_ar' => 'محور أول', 'title_en' => 'First axis', 'total_hours' => 10])->assertCreated();
        $sub = Program::find($res->json('data.id'));

        $this->assertSame($main->id, $sub->parent_id);
        $this->assertSame('sub', $sub->kind);
        $this->assertSame($main->category_id, $sub->category_id);
        $this->assertSame([$skill->id], $sub->skills->pluck('id')->all());
        $this->assertSame('MAIN-1-S1', $sub->code);
        $this->assertSame(1, $sub->groups()->count());

        $this->asUser($admin)->postJson("/api/v1/admin/programs/{$sub->id}/sub-programs", ['title_ar' => 'س', 'title_en' => 's'])->assertUnprocessable();
        $this->asUser($this->makeEmployee()->user)->postJson("/api/v1/admin/programs/{$main->id}/sub-programs", ['title_ar' => 'س', 'title_en' => 's'])->assertForbidden();
    }

    public function test_the_tree_rolls_groups_seats_hours_and_completion_up_to_the_main_program(): void
    {
        $main = $this->makeProgram(['code' => 'ROOT', 'total_hours' => 20, 'capacity' => 10]);
        $admin = $this->admin();
        $subId = $this->asUser($admin)->postJson("/api/v1/admin/programs/{$main->id}/sub-programs", ['title_ar' => 'فرعي', 'title_en' => 'Sub', 'total_hours' => 8, 'capacity' => 6])->json('data.id');
        $sub = Program::find($subId);
        Registration::create(['program_id' => $main->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
        Registration::create(['program_id' => $sub->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
        $sub->groups()->first()->update(['status' => TrainingGroup::COMPLETED]);

        $tree = $this->asUser($admin)->getJson("/api/v1/admin/programs/{$main->id}/tree")->assertOk()->json('data');

        $this->assertSame(2, $tree['rollup']['groups']);
        $this->assertSame(2, $tree['rollup']['seats_taken']);
        $this->assertSame(16, $tree['rollup']['capacity']);
        $this->assertEquals(28, $tree['rollup']['hours']);
        $this->assertEquals(50, $tree['rollup']['completion_percent']);
        $this->assertCount(1, $tree['children']);
        $this->assertSame(1, $tree['children'][0]['rollup']['groups']);
    }

    public function test_units_axes_and_competencies_are_saved_in_order(): void
    {
        $program = $this->makeProgram();
        [$a, $b] = Skill::take(2)->get();

        $res = $this->asUser($this->admin())->putJson("/api/v1/admin/programs/{$program->id}/units", ['units' => [
            ['title_ar' => 'الوحدة الثانية', 'title_en' => 'Unit two', 'hours' => 4, 'objectives' => ['هدف'], 'skill_ids' => [$b->id]],
            ['title_ar' => 'الوحدة الأولى', 'title_en' => 'Unit one', 'hours' => 6, 'skill_ids' => [$a->id, $b->id]],
        ]])->assertOk();

        $units = $program->fresh()->units;
        $this->assertSame(['Unit two', 'Unit one'], $units->pluck('title_en')->all());
        $this->assertSame([0, 1], $units->pluck('sort_order')->all());
        $this->assertSame(2, $units[1]->skills()->count());
        $this->assertCount(2, $res->json('data'));

        // Removing a unit by leaving it out.
        $this->asUser($this->admin())->putJson("/api/v1/admin/programs/{$program->id}/units", ['units' => [['title_ar' => 'وحدة', 'title_en' => 'Only', 'hours' => 1]]])->assertOk();
        $this->assertCount(1, $program->fresh()->units);
    }

    public function test_a_certificate_remembers_the_group_of_its_registration(): void
    {
        $program = $this->makeProgram(['requires_evaluation' => false]);
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'self', 'status' => Registration::STATUS_COMPLETED, 'attendance_percent' => 100]);
        $certificate = app(CertificateService::class)->issue($registration->load('program', 'employee.user'));

        $this->assertInstanceOf(Certificate::class, $certificate);
        $this->assertSame($registration->training_group_id, $certificate->training_group_id);
        $this->assertNotNull($certificate->training_group_id);
        $this->assertInstanceOf(ProgramSession::class, $this->makeSession($program, now()->addDay()));
    }
}
