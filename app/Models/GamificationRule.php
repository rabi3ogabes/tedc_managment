<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event', 'points', 'caps', 'conditions', 'is_active'])]
class GamificationRule extends Model
{
    use HasUuids;

    protected $casts = ['caps' => 'array', 'conditions' => 'array', 'is_active' => 'boolean', 'points' => 'integer'];
}
