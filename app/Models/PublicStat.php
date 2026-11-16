<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'label_ar', 'label_en', 'source', 'value', 'icon', 'sort_order', 'is_visible'])]
class PublicStat extends Model
{
    use HasUuids;

    protected $casts = ['is_visible' => 'boolean'];
}
