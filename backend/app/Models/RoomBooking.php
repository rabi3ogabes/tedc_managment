<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A room booked for something other than a training session (or linked to one). */
#[Fillable(['room_id', 'purpose', 'title', 'starts_at', 'ends_at', 'booked_by', 'session_id', 'status', 'attendees', 'notes'])]
class RoomBooking extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(TrainingRoom::class, 'room_id');
    }

    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }
}
