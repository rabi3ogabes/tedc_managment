<?php

namespace Tests\Feature;

use App\Models\ClassroomObservation;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Role;
use App\Models\Skill;
use App\Models\TrainingPlan;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CompetencyGapTest extends TestCase
{
    private function planner()
    {
        return $this->makeUser(Role::PLANNING_HEAD);
    }

    private function skill(string $code, array $extra = []): Skill
    {
        return Skill::create($extra + ['code' => $code, 'name_en' => ucfirst($code), 'name_ar' => $code, 'category' => 'pedagogy']);
    }

    public function test_the_framework_holds_domains_descriptors_and_required_levels_per_job(): void
    {
        $planner = $this->planner();
        $domain = $this->asUser($planner)->postJson('/api/v1/admin/competency-domains', ['code' => 'assess', 'name_ar' => 'التقويم', 'name_en' => 'Assessment'])->assertCreated()->json('data.id');
        $res = $this->asUser($planner)->postJson('/api/v1/admin/competencies', [
            'code' => 'rubrics', 'name_ar' => 'المقاييس', 'name_en' => 'Rubrics', 'category' => 'assessment', 'domain_id' => $domain, 'licence_relevant' => true,
            'descriptors' => [1 => ['ar' => 'مبتدئ', 'en' => 'Beginner'], 4 => ['ar' => 'خبير', 'en' => 'Expert']],
        ])->assertCreated();
        $skill = $res->json('data.id');
        $job = JobTitle::first();

        $this->asUser($planner)->putJson("/api/v1/admin/job-titles/{$job->id}/requirements", ['requirements' => [['skill_id' => $skill, 'required_level' => 4, 'weight' => 2], ['skill_id' => $skill, 'education_stage' => 'primary', 'required_level' => 3]]])->assertOk();
        $list = $this->asUser($planner)->getJson("/api/v1/admin/job-titles/{$job->id}/requirements")->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertSame('Beginner', Skill::find($skill)->descriptors[1]['en']);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/competencies', ['code' => 'x', 'name_ar' => 'x', 'name_en' => 'x', 'category' => 'x'])->assertForbidden();
    }

    public function test_the_framework_exports_and_imports_as_a_spreadsheet(): void
    {
        $planner = $this->planner();
        $this->skill('alpha', ['licence_relevant' => true]);

        $csv = $this->asUser($planner)->get('/api/v1/admin/competencies/export?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('alpha', $csv);

        $file = UploadedFile::fake()->createWithContent('framework.csv', "code,name_ar,name_en,category,domain,licence_relevant,level_1_ar,level_1_en\nbeta,بيتا,Beta,pedagogy,planning,1,مبتدئ,Beginner\ngamma,,Gamma,pedagogy,,0,,\n");
        $res = $this->asUser($planner)->post('/api/v1/admin/competencies/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, $res->json('data.created'));
        $this->assertSame(1, count($res->json('data.errors')));
        $this->assertSame('Beginner', Skill::where('code', 'beta')->first()->descriptors[1]['en']);
    }

    public function test_the_current_level_blends_verified_level_and_observation_scores_and_lists_the_evidence(): void
    {
        $planner = $this->planner();
        $skill = $this->skill('rubrics');
        $employee = $this->makeEmployee();
        $employee->skills()->attach($skill->id, ['level' => 4, 'source' => 'supervisor', 'verified_at' => now()]);
        ClassroomObservation::create(['employee_id' => $employee->id, 'observed_on' => today()->subMonths(2), 'scores' => [$skill->id => 2], 'source' => 'import']);
        $this->asUser($planner)->putJson("/api/v1/admin/job-titles/{$employee->job_title_id}/requirements", ['requirements' => [['skill_id' => $skill->id, 'required_level' => 5]]])->assertOk();

        $res = $this->asUser($planner)->getJson("/api/v1/admin/gaps/employees/{$employee->id}")->assertOk();
        $row = collect($res->json('data.competencies'))->firstWhere('skill_id', $skill->id);

        $this->assertEquals(3.2, $row['current_level']);
        $this->assertSame(5, $row['required_level']);
        $this->assertSame(2, $row['gap']);
        $this->assertContains('verified', array_column($row['evidence'], 'type'));
        $this->assertContains('observation', array_column($row['evidence'], 'type'));
    }

    public function test_the_gap_analysis_ranks_skills_by_gap_headcount_and_licence_weight_and_finds_uncovered_gaps(): void
    {
        $planner = $this->planner();
        $covered = $this->skill('covered');
        $uncovered = $this->skill('uncovered', ['licence_relevant' => true]);
        $program = $this->makeProgram();
        $program->skills()->attach($covered->id, ['target_level' => 4]);
        $job = JobTitle::where('code', 'TEACHER')->first();
        $this->asUser($planner)->putJson("/api/v1/admin/job-titles/{$job->id}/requirements", ['requirements' => [['skill_id' => $covered->id, 'required_level' => 4], ['skill_id' => $uncovered->id, 'required_level' => 4]]])->assertOk();
        foreach (range(1, 3) as $i) {
            $e = $this->makeEmployee();
            $e->skills()->attach($covered->id, ['level' => 2, 'source' => 'supervisor', 'verified_at' => now()]);
            $e->skills()->attach($uncovered->id, ['level' => 2, 'source' => 'supervisor', 'verified_at' => now()]);
        }

        $res = $this->asUser($planner)->getJson('/api/v1/admin/gaps?group_by=skill')->assertOk();
        $rows = collect($res->json('data.rows'));
        $this->assertSame($uncovered->id, $rows->first()['key'], 'the licence-relevant skill ranks first');
        $this->assertSame(3, $rows->firstWhere('key', $covered->id)['employees']);
        $this->assertTrue($rows->firstWhere('key', $uncovered->id)['uncovered']);
        $this->assertFalse($rows->firstWhere('key', $covered->id)['uncovered']);
        $this->assertNotEmpty($rows->first()['explanation_en']);
        $this->assertSame([$uncovered->id], array_column($res->json('data.uncovered'), 'key'));
    }

    public function test_gaps_can_be_sent_to_the_plan_draft(): void
    {
        $planner = $this->planner();
        $skill = $this->skill('digital');
        $job = JobTitle::where('code', 'TEACHER')->first();
        $this->asUser($planner)->putJson("/api/v1/admin/job-titles/{$job->id}/requirements", ['requirements' => [['skill_id' => $skill->id, 'required_level' => 4]]])->assertOk();
        foreach (range(1, 4) as $i) {
            $this->makeEmployee()->skills()->attach($skill->id, ['level' => 1, 'source' => 'supervisor', 'verified_at' => now()]);
        }
        $plan = TrainingPlan::create(['year' => 2027, 'version' => 1, 'title_ar' => 'خ', 'title_en' => 'P', 'status' => 'draft']);

        $res = $this->asUser($planner)->postJson('/api/v1/admin/gaps/to-plan', ['plan_id' => $plan->id, 'skill_ids' => [$skill->id]])->assertOk();
        $this->assertSame(1, $res->json('data.added'));
        $item = $plan->items()->first();
        $this->assertSame('Digital', $item->title_en);
        $this->assertSame(4, $item->planned_seats);
        $this->assertNotEmpty($item->rationale_en);
        $this->assertSame(0, $this->asUser($planner)->postJson('/api/v1/admin/gaps/to-plan', ['plan_id' => $plan->id, 'skill_ids' => [$skill->id]])->json('data.added'));
    }

    public function test_the_gap_view_needs_its_permission_and_scope(): void
    {
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/gaps')->assertForbidden();
        $this->assertInstanceOf(Employee::class, $this->makeEmployee());
    }
}
