<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_id', 'field', 'operator', 'value', 'message_en', 'message_ar', 'is_mandatory', 'sort_order', 'is_generated'])]
class EligibilityRule extends Model
{
    use HasUuids;

    public const FIELDS = ['job_title', 'job_category', 'department', 'school_type', 'school_stage', 'education_stage', 'region', 'experience_years', 'completed_program', 'skill_level', 'qualification', 'specialization', 'gender', 'nationality', 'school', 'age', 'experience_moe_years', 'experience_outside_years', 'experience_current_title_years', 'grade_level', 'subject', 'grade_taught', 'appraisal_min_rating', 'appraisal_avg_rating', 'equivalent_completed', 'has_licence'];

    public const OPERATORS = ['eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'completed', 'not_completed', 'has_skill', 'lacks_skill', 'includes', 'excludes'];

    protected $casts = ['value' => 'array', 'is_mandatory' => 'boolean', 'is_generated' => 'boolean'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
