<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\Skill;
use App\Services\Eligibility\EligibilityEngine;
use Illuminate\Support\Str;
use Tests\TestCase;

class EligibilityEngineTest extends TestCase
{
    /**
     * IF Employee = Teacher AND Experience > 2 years AND Previous Program != Completed THEN eligible.
     */
    public function test_canonical_rule_example(): void
    {
        $prerequisite = $this->makeProgram(['code' => 'PREV-1']);
        $program = $this->makeProgram();
        $program->eligibilityRules()->createMany([
            ['field' => 'job_title', 'operator' => 'eq', 'value' => 'TEACHER'],
            ['field' => 'experience_years', 'operator' => 'gt', 'value' => 2],
            ['field' => 'completed_program', 'operator' => 'not_completed', 'value' => ['PREV-1']],
        ]);
        $engine = app(EligibilityEngine::class);

        $eligible = $this->makeEmployee(['experience_years' => 3]);
        $result = $engine->evaluate($program, $eligible)->jsonSerialize();
        $this->assertTrue($result['eligible']);
        $this->assertSame('green', $result['color']);

        $junior = $this->makeEmployee(['experience_years' => 1.5]);
        $result = $engine->evaluate($program, $junior)->jsonSerialize();
        $this->assertFalse($result['eligible']);
        $this->assertSame('red', $result['color']);
        $this->assertStringContainsString('سنوات الخبرة', $result['summary']);

        $admin = $this->makeEmployee(['experience_years' => 8], null, 'ADMIN_OFFICER');
        $this->assertFalse($engine->evaluate($program, $admin)->eligible);

        Registration::create(['program_id' => $prerequisite->id, 'employee_id' => $eligible->id, 'source' => 'self', 'status' => Registration::STATUS_COMPLETED]);
        $this->assertFalse($engine->evaluate($program, $eligible->fresh())->eligible);
    }

    public function test_optional_rules_only_warn(): void
    {
        $program = $this->makeProgram();
        $program->eligibilityRules()->create(['field' => 'qualification', 'operator' => 'eq', 'value' => 'phd', 'is_mandatory' => false]);

        $result = app(EligibilityEngine::class)->evaluate($program, $this->makeEmployee());

        $this->assertTrue($result->eligible);
        $this->assertCount(1, $result->warnings());
    }

    public function test_target_groups_restrict_audience(): void
    {
        $program = $this->makeProgram();
        $program->targetGroups()->create(['education_stage' => 'secondary']);

        $engine = app(EligibilityEngine::class);
        $this->assertFalse($engine->evaluate($program, $this->makeEmployee(['education_stage' => 'primary']))->eligible);
        $this->assertTrue($engine->evaluate($program, $this->makeEmployee(['education_stage' => 'secondary']))->eligible);
    }

    public function test_skill_rules(): void
    {
        $program = $this->makeProgram();
        $program->eligibilityRules()->create(['field' => 'skill_level', 'operator' => 'has_skill', 'value' => ['skill' => 'classroom_management', 'min_level' => 3]]);
        $employee = $this->makeEmployee();
        $engine = app(EligibilityEngine::class);

        $this->assertFalse($engine->evaluate($program, $employee)->eligible);

        $employee->skills()->attach(Skill::where('code', 'classroom_management')->value('id'), ['id' => (string) Str::uuid(), 'level' => 3]);
        $this->assertTrue($engine->evaluate($program, $employee->fresh())->eligible);
    }
}
