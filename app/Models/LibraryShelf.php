<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'user_id', 'progress', 'position'])]
class LibraryShelf extends Model
{
    use HasUuids;

    protected $table = 'library_shelf';

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'item_id');
    }

    protected $casts = ['progress' => 'float'];
}
