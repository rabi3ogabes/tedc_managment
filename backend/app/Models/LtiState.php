<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['state', 'nonce', 'tool_id', 'user_id', 'lesson_id', 'purpose', 'context', 'expires_at', 'used_at'])]
class LtiState extends Model
{
    use HasUuids;

    public function tool(): BelongsTo
    {
        return $this->belongsTo(LtiTool::class, 'tool_id');
    }

    protected $casts = ['context' => 'array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
}
