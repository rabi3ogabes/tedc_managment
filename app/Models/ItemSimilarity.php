<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['item_type', 'item_id', 'other_id', 'score', 'support', 'computed_at'])]
class ItemSimilarity extends Model
{
    use HasUuids;

    protected $table = 'item_similarity';

    public $timestamps = false;

    protected $casts = ['computed_at' => 'datetime'];
}
