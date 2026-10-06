<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'user_id', 'outcome', 'ip', 'user_agent', 'request_id', 'meta', 'created_at'])]
class SecurityEvent extends Model
{
    use HasUuids;

    protected $table = 'security_events';

    public $timestamps = false;

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];
}
