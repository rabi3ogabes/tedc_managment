<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A way a survey reaches people: a public link (with its QR) or a set of invitations. */
#[Fillable(['survey_id', 'channel', 'slug', 'duplicate_rule', 'max_responses', 'is_active', 'qr_version', 'submissions_count', 'created_by', 'revoked_at'])]
class SurveyDistribution extends Model
{
    use HasUuids;

    public const CHANNELS = ['public', 'invitation'];

    public const RULES = ['open', 'device', 'email_code'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'max_responses' => 'integer', 'qr_version' => 'integer', 'submissions_count' => 'integer', 'revoked_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class, 'distribution_id');
    }

    public function isFull(): bool
    {
        return $this->max_responses !== null && $this->submissions_count >= $this->max_responses;
    }
}
