<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['integration_key', 'direction', 'operation', 'status', 'duration_ms', 'request_summary', 'response_summary', 'error', 'correlation_id', 'created_at'])]
class IntegrationLog extends Model
{
    use HasUuids;

    protected $casts = ['request_summary' => 'array', 'response_summary' => 'array', 'created_at' => 'datetime'];

    public $timestamps = false;
}
