<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['cart_id', 'group_id', 'quantity', 'expires_at'])]
class CartItem extends Model
{
    use HasUuids;

    protected $table = 'cart_items';

    protected $casts = ['expires_at' => 'datetime'];
}
