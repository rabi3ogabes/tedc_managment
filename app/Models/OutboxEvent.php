<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'payload', 'correlation_id', 'occurred_at'])]
class OutboxEvent extends Model
{
    use HasUuids;

    protected $casts = ['payload' => 'array', 'occurred_at' => 'datetime'];

    public $timestamps = false;

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'outbox_event_id');
    }
}
