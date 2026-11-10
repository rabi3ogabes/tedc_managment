<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['asker_id', 'program_id', 'group_id', 'lesson_id', 'visibility', 'subject', 'body', 'status', 'answer', 'answered_by', 'answered_at', 'due_at', 'post_id'])]
class CourseQuestion extends Model
{
    use HasUuids;

    protected $casts = ['answered_at' => 'datetime', 'due_at' => 'datetime'];

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asker_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }
}
