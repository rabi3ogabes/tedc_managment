<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Firebase Cloud Messaging registration token of a signed-in mobile device. */
#[Fillable(['user_id', 'token', 'platform', 'locale', 'app_version', 'device_name', 'last_seen_at'])]
class DeviceToken extends Model
{
    use HasUuids;

    protected $hidden = ['token'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
