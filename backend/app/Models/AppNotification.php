<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notification. Rows inserted here are streamed to clients through
 * Supabase Realtime (see supabase/migrations for the publication + RLS policy).
 */
#[Fillable(['user_id', 'type', 'title_en', 'title_ar', 'body_en', 'body_ar', 'data', 'read_at', 'seen_at', 'campaign_id'])]
class AppNotification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    protected $casts = ['data' => 'array', 'read_at' => 'datetime', 'seen_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
