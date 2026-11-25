<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A model's output for a survey: a summary of open-text answers or question suggestions (Gate 8). */
#[Fillable(['survey_id', 'version_id', 'question_id', 'kind', 'payload', 'answers_count', 'model', 'redactions', 'created_by', 'created_at'])]
class SurveyAiInsight extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const KINDS = ['summary', 'suggestions'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime', 'answers_count' => 'integer', 'redactions' => 'integer'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
