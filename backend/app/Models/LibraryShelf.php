<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['item_id', 'user_id', 'progress', 'position'])]
class LibraryShelf extends Model
{
    use HasUuids;

    protected $table = 'library_shelf';

    protected $casts = ['progress' => 'float'];
}
