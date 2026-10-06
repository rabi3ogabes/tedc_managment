<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['page', 'type', 'config', 'sort_order', 'is_visible', 'audience', 'starts_at', 'ends_at', 'updated_by'])]
class PageBlock extends Model
{
    use HasUuids;

    protected $casts = ['config' => 'array', 'is_visible' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
}
