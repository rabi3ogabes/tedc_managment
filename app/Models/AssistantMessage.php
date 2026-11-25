<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'role', 'content', 'citations', 'meta', 'feedback', 'feedback_reason'])]
class AssistantMessage extends Model
{
    use HasUuids;

    protected $table = 'assistant_messages';

    protected $casts = ['citations' => 'array', 'meta' => 'array'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }
}
