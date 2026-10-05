<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['item_id', 'user_id', 'stars', 'review'])]
class LibraryReview extends Model
{
    use HasUuids;

    protected $casts = [];
}
