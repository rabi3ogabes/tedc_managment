<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person invited to a survey and whether they answered. Never joined to {@see SurveySubmission}:
 * in confidential and anonymous surveys this row is the only trace of the person.
 */
#[Fillable(['survey_id', 'user_id', 'invited_at', 'notified_at', 'reminded_at', 'responded_at'])]
class SurveyParticipant extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'notified_at' => 'datetime', 'reminded_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
