<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'category', 'priority', 'subject', 'description', 'page_url', 'context', 'screenshot_path', 'saaed_ticket_no', 'saaed_status', 'status', 'attempts', 'last_error', 'last_synced_at'])]
class SupportTicket extends Model
{
    use HasUuids;

    protected $casts = ['context' => 'array', 'last_synced_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
