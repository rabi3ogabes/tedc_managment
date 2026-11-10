<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['session_id', 'meeting_id', 'graph_session_id', 'join_url', 'organizer_upn', 'kind', 'status', 'lobby', 'attendees_hash', 'invited_count', 'recording_url', 'attendance_synced_at', 'attendance_attempts', 'last_error'])]
class TeamsMeeting extends Model
{
    use HasUuids;

    protected $casts = ['attendance_synced_at' => 'datetime', 'invited_count' => 'integer'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'session_id');
    }
}
