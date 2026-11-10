<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['survey_id', 'user_id', 'school_id', 'job_title_id', 'experience_years', 'specialization', 'nationality', 'gender', 'education_stage', 'answers', 'duration_seconds', 'submitted_at'])]
class NeedsSurveyResponse extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['answers' => 'array', 'experience_years' => 'float', 'submitted_at' => 'datetime', 'duration_seconds' => 'integer'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NeedsSurvey::class, 'survey_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }
}
