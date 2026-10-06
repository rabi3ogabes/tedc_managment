<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['metric', 'target', 'comparator', 'editable'])]
class KpiTarget extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'metric';

    protected $keyType = 'string';

    protected $casts = ['target' => 'float', 'editable' => 'boolean'];
}
