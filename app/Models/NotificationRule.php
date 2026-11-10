<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event', 'name', 'audience_filter', 'channels', 'enabled', 'quiet_hours', 'delay_minutes', 'program_id', 'category_id', 'priority'])]
class NotificationRule extends Model
{
    use HasUuids;

    protected $casts = ['audience_filter' => 'array', 'channels' => 'array', 'quiet_hours' => 'array', 'enabled' => 'boolean', 'delay_minutes' => 'integer'];
}
