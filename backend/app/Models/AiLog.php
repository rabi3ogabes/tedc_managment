<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['feature', 'user_id', 'connection_id', 'model', 'status', 'reason', 'residency', 'prompt_chars', 'tokens_in', 'tokens_out', 'latency_ms', 'redactions', 'created_at'])]
class AiLog extends Model
{
    use HasUuids;

    protected $table = 'ai_logs';

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];
}
