<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['period', 'scope', 'scope_id', 'period_start', 'ranks'])]
class LeaderboardSnapshot extends Model
{
    use HasUuids;

    protected $casts = ['ranks' => 'array', 'period_start' => 'date'];
}
