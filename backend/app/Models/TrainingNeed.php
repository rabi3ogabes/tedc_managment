<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['school_id', 'submitted_by', 'skill_id', 'skill_name', 'employees_count', 'priority', 'reason', 'target_job_title_id', 'target_group', 'status', 'program_id', 'reviewed_by', 'review_notes', 'survey_id', 'need_index'])]
class TrainingNeed extends Model
{
    use Auditable, HasUuids;

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    public const PRIORITY_WEIGHT = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function targetJobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'target_job_title_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NeedsSurvey::class, 'survey_id');
    }
}
