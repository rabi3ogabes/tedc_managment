<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'subject_type', 'subject_id', 'label', 'score', 'reasons', 'resolved_at'])]
class RiskFlag extends Model
{
    use HasUuids;

    protected $table = 'risk_flags';

    protected $casts = ['reasons' => 'array', 'resolved_at' => 'datetime', 'score' => 'float'];
}
