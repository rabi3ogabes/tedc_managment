<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['notification_id', 'user_id', 'channel', 'type', 'status', 'reason', 'to', 'attempts', 'sent_at'])]
class NotificationDelivery extends Model
{
    use HasUuids;

    protected $casts = ['sent_at' => 'datetime', 'attempts' => 'integer'];
}
