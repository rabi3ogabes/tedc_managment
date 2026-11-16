<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "You were assigned as the trainer of …" — one per trainer and programme (or group); shown on the trainer's home until dismissed. */
#[Fillable(['user_id', 'trainer_id', 'program_id', 'group_id', 'scope_key', 'kind', 'seen_at', 'dismissed_at'])]
class TrainerNotice extends Model
{
    use HasUuids;

    protected $casts = ['seen_at' => 'datetime', 'dismissed_at' => 'datetime'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'group_id');
    }
}
