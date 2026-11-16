<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['dimension', 'subject_id', 'label', 'year', 'value', 'low', 'high', 'model', 'explanation', 'history'])]
class Forecast extends Model
{
    use HasUuids;

    protected $table = 'forecasts';

    protected $casts = ['history' => 'array', 'value' => 'float', 'low' => 'float', 'high' => 'float'];
}
