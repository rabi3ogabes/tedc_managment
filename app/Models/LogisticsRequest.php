<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request to the logistics team for equipment, catering, printing, IT or arrangement. */
#[Fillable(['session_id', 'group_id', 'booking_id', 'requested_by', 'items', 'notes', 'needed_by', 'status', 'assignee_id', 'completed_at', 'comment', 'overdue_notified_at'])]
class LogisticsRequest extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['items' => 'array', 'needed_by' => 'datetime', 'completed_at' => 'datetime', 'overdue_notified_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'session_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
