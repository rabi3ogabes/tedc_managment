<?php

namespace App\Services;

use App\Models\JobTitle;
use App\Models\Program;
use App\Services\NeedsSurveys\SurveyAudience;

/**
 * Turns a target audience (the filters chosen when creating a program) into eligibility rules, so the
 * registration engine enforces exactly the group the center meant to reach.
 */
class AudienceRules
{
    /** audience key => [rule field, operator] for list filters. */
    private const LISTS = [
        'specializations' => ['specialization', 'in'],
        'genders' => ['gender', 'in'],
        'nationalities' => ['nationality', 'in'],
        'qualifications' => ['qualification', 'in'],
        'stages' => ['education_stage', 'in'],
        'school_types' => ['school_type', 'in'],
        'regions' => ['region', 'in'],
        'school_ids' => ['school', 'in'],
        'grade_levels' => ['grade_level', 'in'],
        'subjects' => ['subject', 'includes'],
        'grades_taught' => ['grade_taught', 'includes'],
    ];

    /** @return list<array{field: string, operator: string, value: mixed}> */
    public function toRules(?array $audience): array
    {
        $a = SurveyAudience::clean($audience);
        $rules = [];

        if (isset($a['job_title_ids'])) {
            $rules[] = ['field' => 'job_title', 'operator' => 'in', 'value' => JobTitle::whereIn('id', $a['job_title_ids'])->pluck('code')->all()];
        }
        foreach (self::LISTS as $key => [$field, $operator]) {
            if (isset($a[$key])) {
                $rules[] = ['field' => $field, 'operator' => $operator, 'value' => $a[$key]];
            }
        }
        foreach ([['experience_min', 'experience_years', 'gte'], ['experience_max', 'experience_years', 'lte'], ['age_min', 'age', 'gte'], ['age_max', 'age', 'lte'], ['experience_moe_min', 'experience_moe_years', 'gte'], ['experience_moe_max', 'experience_moe_years', 'lte'], ['experience_outside_min', 'experience_outside_years', 'gte'], ['experience_outside_max', 'experience_outside_years', 'lte']] as [$key, $field, $operator]) {
            if (isset($a[$key])) {
                $rules[] = ['field' => $field, 'operator' => $operator, 'value' => $a[$key]];
            }
        }

        return $rules;
    }

    /** Stores the audience on the program and replaces its generated rules (manual rules are kept). */
    public function apply(Program $program, ?array $audience): void
    {
        $clean = SurveyAudience::clean($audience);
        $program->update(['audience' => $clean ?: null]);
        $program->eligibilityRules()->where('is_generated', true)->delete();

        $order = (int) $program->eligibilityRules()->max('sort_order');
        foreach ($this->toRules($clean) as $rule) {
            $program->eligibilityRules()->create($rule + ['is_mandatory' => true, 'is_generated' => true, 'sort_order' => ++$order]);
        }
        $program->unsetRelation('eligibilityRules');
    }
}
