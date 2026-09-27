<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_id', 'field', 'operator', 'value', 'message_en', 'message_ar', 'is_mandatory', 'sort_order'])]
class EligibilityRule extends Model
{
    use HasUuids;

    public const FIELDS = ['job_title', 'job_category', 'department', 'school_type', 'school_stage', 'education_stage', 'region', 'experience_years', 'completed_program', 'skill_level', 'qualification'];

    public const OPERATORS = ['eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'completed', 'not_completed', 'has_skill', 'lacks_skill'];

    protected $casts = ['value' => 'array', 'is_mandatory' => 'boolean'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
