<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A trainee's answer to an in-video interaction. */
#[Fillable(['interaction_id', 'registration_id', 'answer', 'correct', 'answered_at'])]
class VideoInteractionResponse extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['answer' => 'array', 'correct' => 'boolean', 'answered_at' => 'datetime'];
    }
}
