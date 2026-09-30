<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'platform', 'team', 'role_label', 'started_at', 'last_seen_at', 'hits', 'idle_seconds', 'last_path', 'device', 'app_version'])]
class PresenceSession extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $casts = ['started_at' => 'datetime', 'last_seen_at' => 'datetime', 'hits' => 'integer', 'idle_seconds' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function minutes(): int
    {
        return max(1, (int) ceil($this->started_at->diffInSeconds($this->last_seen_at) / 60));
    }
}
