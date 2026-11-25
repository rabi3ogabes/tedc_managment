<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The answers a submission held before the respondent edited it. */
#[Fillable(['submission_id', 'version_id', 'previous_answers', 'created_at'])]
class SurveySubmissionEdit extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['previous_answers' => 'array', 'created_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(SurveySubmission::class, 'submission_id');
    }
}
