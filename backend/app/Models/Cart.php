<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'entity_account_id', 'discount_code'])]
class Cart extends Model
{
    use HasUuids;

    protected $table = 'carts';
}
