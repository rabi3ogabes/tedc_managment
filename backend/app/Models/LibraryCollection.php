<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name_ar', 'name_en', 'is_featured', 'sort_order'])]
class LibraryCollection extends Model
{
    use HasUuids;

    protected $casts = ['is_featured' => 'boolean'];
}
