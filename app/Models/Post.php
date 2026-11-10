<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['space_id', 'author_id', 'kind', 'title', 'body', 'attachments', 'is_pinned', 'is_locked', 'accepted_answer_id', 'status', 'edits', 'edited_at', 'comments_count', 'reactions_count'])]
class Post extends Model
{
    use HasUuids;

    protected $casts = ['attachments' => 'array', 'edits' => 'array', 'is_pinned' => 'boolean', 'is_locked' => 'boolean', 'edited_at' => 'datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function poll(): HasOne
    {
        return $this->hasOne(Poll::class, 'post_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
