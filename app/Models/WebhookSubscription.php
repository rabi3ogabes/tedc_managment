<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'url', 'secret', 'events', 'enabled', 'created_by'])]
class WebhookSubscription extends Model
{
    use HasUuids;

    protected $casts = ['events' => 'array', 'enabled' => 'boolean'];
}
