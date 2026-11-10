<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name_ar', 'name_en', 'rule'])]
class JobGroup extends Model
{
    use HasUuids;

    protected $casts = ['rule' => 'array'];
}
