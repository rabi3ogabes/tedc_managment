<?php

namespace App\Services\Eligibility;

use App\Models\EligibilityRule;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\Skill;
use App\Models\TargetGroup;

/**
 * Smart Eligibility Engine.
 *
 * Every program owns an ordered list of declarative rules
 * (field / operator / value). Mandatory rules are AND-ed together; optional
 * rules only produce warnings. Target groups, when defined, act as an extra
 * mandatory rule: the employee must match at least one group.
 *
 * Example — "Teacher AND experience > 2 years AND has not completed PROG-101":
 *   [job_title, eq, "TEACHER"], [experience_years, gt, 2], [completed_program, not_completed, "PROG-101"]
 */
class EligibilityEngine
{
    public function evaluate(Program $program, ?Employee $employee): EligibilityResult
    {
        if (! $employee) {
            return new EligibilityResult(false, [[
                'key' => 'profile', 'field' => 'profile', 'passed' => false, 'mandatory' => true,
                'message' => __('eligibility.no_employee_profile'),
            ]]);
        }

        $context = EmployeeContext::fromEmployee($employee);
        $program->loadMissing(['eligibilityRules', 'targetGroups']);

        $checks = [];

        if ($program->targetGroups->isNotEmpty()) {
            $checks[] = $this->checkTargetGroups($program, $context);
        }

        foreach ($program->eligibilityRules as $rule) {
            $checks[] = $this->checkRule($rule, $context);
        }

        $eligible = collect($checks)->every(fn ($c) => $c['passed'] || ! $c['mandatory']);

        return new EligibilityResult($eligible, $checks);
    }

    /**
     * Evaluates a single rule definition against a context (used by the rule builder preview).
     */
    public function passes(string $field, string $operator, mixed $expected, EmployeeContext $context): bool
    {
        $actual = $context->value($field);

        return match ($operator) {
            'eq' => $this->normalize($actual) === $this->normalize($expected),
            'neq' => $this->normalize($actual) !== $this->normalize($expected),
            'in' => in_array($this->normalize($actual), array_map([$this, 'normalize'], (array) $expected), true),
            'not_in' => ! in_array($this->normalize($actual), array_map([$this, 'normalize'], (array) $expected), true),
            'gt' => is_numeric($actual) && (float) $actual > (float) $expected,
            'gte' => is_numeric($actual) && (float) $actual >= (float) $expected,
            'lt' => is_numeric($actual) && (float) $actual < (float) $expected,
            'lte' => is_numeric($actual) && (float) $actual <= (float) $expected,
            'completed' => count(array_intersect((array) $expected, $context->completedPrograms)) === count((array) $expected),
            'not_completed' => count(array_intersect((array) $expected, $context->completedPrograms)) === 0,
            'has_skill' => ($context->skills[$expected['skill'] ?? ''] ?? 0) >= (int) ($expected['min_level'] ?? 1),
            'lacks_skill' => ($context->skills[$expected['skill'] ?? ''] ?? 0) < (int) ($expected['min_level'] ?? 1),
            default => false,
        };
    }

    private function checkRule(EligibilityRule $rule, EmployeeContext $context): array
    {
        $passed = $this->passes($rule->field, $rule->operator, $rule->value, $context);
        $custom = app()->getLocale() === 'ar' ? $rule->message_ar : $rule->message_en;

        return [
            'key' => $rule->id,
            'field' => $rule->field,
            'field_label' => __("eligibility.fields.{$rule->field}"),
            'operator' => $rule->operator,
            'passed' => $passed,
            'mandatory' => $rule->is_mandatory,
            'message' => $custom ?: $this->describe($rule, $context),
        ];
    }

    private function checkTargetGroups(Program $program, EmployeeContext $context): array
    {
        $matches = $program->targetGroups->contains(fn (TargetGroup $group) => ($group->job_title_id === null || $group->job_title_id === $context->jobTitleId)
            && ($group->department_id === null || $group->department_id === $context->departmentId)
            && ($group->school_type === null || $group->school_type === $context->schoolType)
            && ($group->education_stage === null || $group->education_stage === $context->educationStage));

        return [
            'key' => 'target_group',
            'field' => 'target_group',
            'field_label' => __('eligibility.target_group'),
            'operator' => 'in',
            'passed' => $matches,
            'mandatory' => true,
            'message' => __($matches ? 'eligibility.target_group_passed' : 'eligibility.target_group_failed'),
        ];
    }

    private function describe(EligibilityRule $rule, EmployeeContext $context): string
    {
        $value = $rule->value;

        return match ($rule->operator) {
            'completed', 'not_completed' => __("eligibility.{$rule->operator}", ['expected' => $this->programNames((array) $value)]),
            'has_skill', 'lacks_skill' => __("eligibility.{$rule->operator}", [
                'expected' => $this->skillName($value['skill'] ?? ''),
                'level' => $value['min_level'] ?? 1,
            ]),
            default => __('eligibility.compare', [
                'field' => __("eligibility.fields.{$rule->field}"),
                'operator' => __("eligibility.operators.{$rule->operator}"),
                'expected' => $this->display($rule->field, $value),
                'actual' => $this->display($rule->field, $context->value($rule->field)) ?: __('eligibility.none'),
            ]),
        };
    }

    private function display(string $field, mixed $value): string
    {
        $values = (array) $value;

        if ($field === 'job_title') {
            $titles = JobTitle::whereIn('code', $values)->get();
            $values = array_map(fn ($code) => $titles->firstWhere('code', $code)?->translate('name') ?? $code, $values);
        }

        return implode(', ', array_map(function ($v) {
            if (is_float($v)) {
                return rtrim(rtrim(number_format($v, 1), '0'), '.');
            }
            $key = 'eligibility.values.'.$v;

            return is_string($v) && __($key) !== $key ? __($key) : (string) $v;
        }, $values));
    }

    private function programNames(array $codes): string
    {
        $programs = Program::whereIn('code', $codes)->get();

        return implode(', ', array_map(fn ($code) => $programs->firstWhere('code', $code)?->translate('title') ?? $code, $codes));
    }

    private function skillName(string $code): string
    {
        return Skill::where('code', $code)->first()?->translate('name') ?? $code;
    }

    private function normalize(mixed $value): mixed
    {
        return is_string($value) ? mb_strtolower(trim($value)) : $value;
    }
}
