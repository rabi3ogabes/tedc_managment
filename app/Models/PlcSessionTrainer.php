<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A trainer delivering (part of) a session: lead or co-trainer, with confirmation status. */
#[Fillable(['program_session_id', 'trainer_id', 'role', 'minutes', 'status', 'note'])]
class PlcSessionTrainer extends Model
{
    use HasUuids;

    public const ROLES = ['lead', 'co'];

    public const STATUSES = ['planned', 'confirmed', 'replaced'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'program_session_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
