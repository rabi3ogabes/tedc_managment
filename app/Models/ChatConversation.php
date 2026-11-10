<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['token_hash', 'user_id', 'visitor_name', 'visitor_email', 'locale', 'mode', 'status', 'needs_human', 'admin_unread', 'visitor_unread', 'messages_count', 'user_agent', 'last_message_at'])]
class ChatConversation extends Model
{
    use HasUuids;

    protected $casts = ['needs_human' => 'boolean', 'last_message_at' => 'datetime'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('created_at')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owns(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->token_hash, hash('sha256', $token));
    }
}
