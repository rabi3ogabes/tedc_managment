<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A node of a bank's category tree (course → unit → topic). */
#[Fillable(['bank_id', 'parent_id', 'name_ar', 'name_en', 'sort_order'])]
class BankCategory extends Model
{
    use HasUuids;
}
