<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['state', 'nonce', 'tool_id', 'user_id', 'lesson_id', 'purpose', 'expires_at', 'used_at'])]
class LtiState extends Model
{
    use HasUuids;

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];
}
