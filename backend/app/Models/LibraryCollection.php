<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name_ar', 'name_en', 'is_featured', 'sort_order'])]
class LibraryCollection extends Model
{
    use HasUuids;

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(LibraryItem::class, 'library_collection_items', 'collection_id', 'item_id')->withPivot('sort_order')->orderByPivot('sort_order');
    }

    protected $casts = ['is_featured' => 'boolean'];
}
