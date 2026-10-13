<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One launch of TEDC by a platform: who, what it opened, and where grades and rosters go back. */
#[Fillable(['platform_id', 'sub', 'user_id', 'message_type', 'context_id', 'resource_link_id', 'target_type', 'target_id', 'registration_id', 'claims', 'ags_lineitem', 'ags_lineitems', 'ags_scopes', 'nrps_url',
    'outcome_url', 'outcome_sourcedid', 'deep_link', 'ticket', 'used_at', 'last_score_at', 'last_score_error'])]
class LtiToolLaunch extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['claims' => 'array', 'ags_scopes' => 'array', 'deep_link' => 'array', 'used_at' => 'datetime', 'last_score_at' => 'datetime'];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(LtiPlatform::class, 'platform_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
