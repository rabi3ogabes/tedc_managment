<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'type', 'value', 'scope', 'scope_id', 'usage_limit', 'per_user_limit', 'used', 'valid_from', 'valid_to', 'source', 'is_active'])]
class DiscountCode extends Model
{
    use HasUuids;

    protected $table = 'discount_codes';

    protected $casts = ['value' => 'float', 'valid_from' => 'datetime', 'valid_to' => 'datetime', 'is_active' => 'boolean'];
}
