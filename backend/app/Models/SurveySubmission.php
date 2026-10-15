<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One submitted answer set (Survey Studio v2), bound to the version that was live when it was submitted.
 * `respondent_user_id` is filled only in identified surveys, `respondent_pseudonym` only in confidential ones;
 * anonymous surveys carry neither. (The legacy lesson survey keeps the `survey_responses` table.)
 */
#[Fillable(['survey_id', 'version_id', 'respondent_user_id', 'respondent_pseudonym', 'segment_snapshot', 'answers', 'status', 'source', 'duration_seconds', 'submitted_at', 'edited_at', 'edit_count'])]
class SurveySubmission extends Model
{
    use HasUuids;

    public const SOURCES = ['web', 'mobile', 'public', 'qr', 'import'];

    protected function casts(): array
    {
        return ['segment_snapshot' => 'array', 'answers' => 'array', 'duration_seconds' => 'integer', 'edit_count' => 'integer', 'submitted_at' => 'datetime', 'edited_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'version_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_user_id');
    }

    public function edits(): HasMany
    {
        return $this->hasMany(SurveySubmissionEdit::class, 'submission_id')->orderByDesc('created_at');
    }
}
