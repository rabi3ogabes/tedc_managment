<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source', 'idempotency_key', 'payload', 'result', 'processed_at'])]
class InboundEvent extends Model
{
    use HasUuids;

    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];
}
