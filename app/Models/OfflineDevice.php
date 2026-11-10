<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone that keeps course content offline (TYP-12). Its content key is stored encrypted and only ever sent to that device. */
#[Fillable(['user_id', 'device_id', 'platform', 'name', 'content_key', 'last_seen_at', 'revoked_at'])]
#[Hidden(['content_key'])]
class OfflineDevice extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['content_key' => 'encrypted', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
