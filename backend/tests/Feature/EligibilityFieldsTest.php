<?php

namespace Tests\Feature;

use App\Models\PerformanceAppraisal;
use App\Models\Program;
use App\Models\ProgramEquivalence;
use App\Models\Registration;
use App\Services\Eligibility\EligibilityEngine;
use App\Services\Eligibility\EmployeeContext;
use Tests\TestCase;

class EligibilityFieldsTest extends TestCase
{
    private function passes(string $field, string $op, mixed $expected, $employee): bool
    {
        return app(EligibilityEngine::class)->passes($field, $op, $expected, EmployeeContext::fromEmployee($employee->fresh()));
    }

    public function test_the_ministry_outside_and_current_title_experience_are_separate_criteria(): void
    {
        $e = $this->makeEmployee(['experience_moe_years' => 6, 'experience_outside_years' => 2, 'current_title_since' => today()->subYears(3)->subDay()]);

        $this->assertTrue($this->passes('experience_moe_years', 'gte', 5, $e));
        $this->assertFalse($this->passes('experience_outside_years', 'gte', 3, $e));
        $this->assertTrue($this->passes('experience_current_title_years', 'gte', 3, $e));
        $this->assertFalse($this->passes('experience_current_title_years', 'gte', 4, $e));
    }

    public function test_grade_level_subjects_and_grades_taught_are_matched(): void
    {
        $e = $this->makeEmployee(['grade_level' => 'G5', 'subjects' => ['Maths', 'Physics'], 'grades_taught' => ['9', '10']]);

        $this->assertTrue($this->passes('grade_level', 'eq', 'g5', $e));
        $this->assertTrue($this->passes('subject', 'includes', ['physics'], $e));
        $this->assertFalse($this->passes('subject', 'includes', ['Arabic'], $e));
        $this->assertTrue($this->passes('grade_taught', 'includes', ['10', '11'], $e));
        $this->assertTrue($this->passes('grade_taught', 'excludes', ['1'], $e));
    }

    public function test_appraisal_ratings_over_the_last_years_are_compared_as_numbers(): void
    {
        $e = $this->makeEmployee();
        $y = now()->year;
        PerformanceAppraisal::create(['employee_id' => $e->id, 'year' => $y - 1, 'rating_code' => 'excellent']);
        PerformanceAppraisal::create(['employee_id' => $e->id, 'year' => $y - 2, 'rating_code' => 'good']);

        $this->assertTrue($this->passes('appraisal_min_rating', 'gte', 3, $e));
        $this->assertFalse($this->passes('appraisal_min_rating', 'gte', 4, $e));
        $this->assertTrue($this->passes('appraisal_avg_rating', 'gte', 3.5, $e));
    }

    public function test_an_equivalent_completed_program_is_visible_to_the_rules_and_the_licence_hook_is_empty_for_now(): void
    {
        $e = $this->makeEmployee();
        $original = $this->makeProgram(['code' => 'ORIG']);
        $copy = $this->makeProgram(['code' => 'COPY']);
        ProgramEquivalence::create(['program_id' => $copy->id, 'equivalent_program_id' => $original->id, 'bidirectional' => true]);
        Registration::create(['program_id' => $original->id, 'employee_id' => $e->id, 'source' => 'self', 'status' => Registration::STATUS_COMPLETED]);

        $this->assertTrue($this->passes('equivalent_completed', 'includes', ['COPY'], $e));
        $this->assertFalse($this->passes('has_licence', 'eq', true, $e));
        $this->assertInstanceOf(Program::class, $copy);
    }
}
