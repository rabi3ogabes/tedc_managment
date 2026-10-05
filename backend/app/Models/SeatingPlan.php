<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Seat grid of a room for a session or group, with who sits where. */
#[Fillable(['room_id', 'session_id', 'group_id', 'layout', 'assignments'])]
class SeatingPlan extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['layout' => 'array', 'assignments' => 'array'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(TrainingRoom::class, 'room_id');
    }
}
