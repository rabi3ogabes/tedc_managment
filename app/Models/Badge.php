<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name_ar', 'name_en', 'description_ar', 'description_en', 'icon_svg', 'tier', 'criteria', 'is_active'])]
class Badge extends Model
{
    use HasUuids;

    protected $casts = ['criteria' => 'array', 'is_active' => 'boolean'];
}
