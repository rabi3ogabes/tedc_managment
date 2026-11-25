<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event', 'status', 'attempts', 'error', 'sent_at'])]
class CaliperEvent extends Model
{
    use HasUuids;

    protected $casts = ['event' => 'array', 'sent_at' => 'datetime'];
}
