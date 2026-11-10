<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['program_id', 'group_id', 'currency', 'rules', 'default_price', 'vat_rate', 'refund_policy', 'is_active'])]
class PriceList extends Model
{
    use HasUuids;

    protected $table = 'price_lists';

    protected $casts = ['rules' => 'array', 'refund_policy' => 'array', 'default_price' => 'float', 'vat_rate' => 'float', 'is_active' => 'boolean'];
}
