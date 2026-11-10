<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['program_id', 'group_id', 'response_rate', 'average', 'threshold', 'notified_at'])]
class SatisfactionAlert extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['notified_at' => 'datetime', 'response_rate' => 'float', 'average' => 'float', 'threshold' => 'float'];
}
