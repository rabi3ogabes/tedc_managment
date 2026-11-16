<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['metric', 'value', 'window', 'meta', 'measured_at'])]
class KpiSample extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $casts = ['value' => 'float', 'meta' => 'array', 'measured_at' => 'datetime'];
}
