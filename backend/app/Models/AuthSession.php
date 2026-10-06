<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['external_id', 'user_id', 'method', 'ip', 'user_agent', 'last_seen_at', 'expires_at', 'stepped_up_at', 'revoked_at', 'revoked_reason'])]
class AuthSession extends Model
{
    use HasUuids;

    protected $casts = ['last_seen_at' => 'datetime', 'expires_at' => 'datetime', 'stepped_up_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
