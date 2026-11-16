<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A trainer's attendance at one session. */
#[Fillable(['session_id', 'trainer_id', 'check_in_at', 'check_out_at', 'method', 'recorded_by', 'notes'])]
class TrainerAttendance extends Model
{
    use HasUuids;

    protected $table = 'trainer_attendance';

    protected function casts(): array
    {
        return ['check_in_at' => 'datetime', 'check_out_at' => 'datetime'];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'session_id');
    }
}
