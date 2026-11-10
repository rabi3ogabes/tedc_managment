<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['notification_id', 'user_id', 'channel', 'type', 'status', 'reason', 'to', 'attempts', 'sent_at', 'read_at', 'delivered_at', 'failed_reason', 'provider_message_id', 'campaign_id', 'not_before'])]
class NotificationDelivery extends Model
{
    use HasUuids;

    protected $casts = ['sent_at' => 'datetime', 'read_at' => 'datetime', 'delivered_at' => 'datetime', 'not_before' => 'datetime', 'attempts' => 'integer'];
}
