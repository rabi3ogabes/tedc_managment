<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'event', 'points', 'source_type', 'source_id', 'note', 'created_by', 'created_at'])]
class PointLedger extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'point_ledger';

    protected $casts = ['points' => 'integer', 'created_at' => 'datetime'];
}
