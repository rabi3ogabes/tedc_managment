<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['resource_type', 'resource_id', 'target_type', 'target_id', 'permission', 'shared_by', 'expires_at'])]
class ResourceShare extends Model
{
    use HasUuids;

    protected $casts = ['expires_at' => 'datetime'];
}
