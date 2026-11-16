<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['level_no', 'name_ar', 'name_en', 'min_points', 'icon'])]
class Level extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'level_no';
}
