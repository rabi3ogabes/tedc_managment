<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A respondent's autosaved draft, keyed by the privacy-mode respondent key. Removed on submit or expiry. */
#[Fillable(['survey_id', 'version_id', 'respondent_key', 'draft_answers', 'page', 'last_seen_at', 'expires_at'])]
class SurveySession extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['draft_answers' => 'array', 'page' => 'integer', 'last_seen_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
