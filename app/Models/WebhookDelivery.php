<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_id', 'outbox_event_id', 'status', 'attempts', 'next_attempt_at', 'last_status', 'last_error', 'delivered_at'])]
class WebhookDelivery extends Model
{
    use HasUuids;

    protected $casts = ['next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }
}
