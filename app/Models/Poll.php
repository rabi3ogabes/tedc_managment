<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['post_id', 'question', 'options', 'multiple', 'anonymous', 'closes_at'])]
class Poll extends Model
{
    use HasUuids;

    protected $casts = ['options' => 'array', 'multiple' => 'boolean', 'anonymous' => 'boolean', 'closes_at' => 'datetime'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'poll_id');
    }
}
