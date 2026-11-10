<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file attached to a file-upload answer, kept in a private bucket and served through short-lived links only. */
#[Fillable(['survey_id', 'respondent_key', 'question_id', 'submission_id', 'bucket', 'path', 'original_name', 'mime', 'size', 'scanned_at'])]
class SurveyFile extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['size' => 'integer', 'scanned_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(SurveySubmission::class, 'submission_id');
    }
}
