<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event', 'is_system', 'name_ar', 'name_en', 'title_ar', 'title_en', 'body_ar', 'body_en', 'enabled', 'push', 'email', 'sms', 'updated_by'])]
class NotificationTemplate extends Model
{
    use HasUuids;

    protected $casts = ['is_system' => 'boolean', 'enabled' => 'boolean', 'push' => 'boolean'];
}
