<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A single-use invitation to one e-mail address; the token is stored hashed and consumed by the submission. */
#[Fillable(['survey_id', 'distribution_id', 'email', 'name', 'token_hash', 'sent_at', 'send_status', 'send_error', 'reminded_at', 'opened_at', 'responded_at', 'expires_at', 'created_by'])]
class SurveyInvitation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'reminded_at' => 'datetime', 'opened_at' => 'datetime', 'responded_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(SurveyDistribution::class, 'distribution_id');
    }

    public function isUsable(): bool
    {
        return ! $this->responded_at && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
