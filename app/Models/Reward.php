<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title_ar', 'title_en', 'description_ar', 'description_en', 'kind', 'cost_points', 'min_level', 'stock', 'is_active'])]
class Reward extends Model
{
    use HasUuids;

    protected $casts = ['is_active' => 'boolean'];
}
