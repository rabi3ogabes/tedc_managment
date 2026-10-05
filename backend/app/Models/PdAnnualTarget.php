<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['year', 'audience', 'min_hours', 'counts'])]
class PdAnnualTarget extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['audience' => 'array', 'counts' => 'array', 'min_hours' => 'float'];
}
