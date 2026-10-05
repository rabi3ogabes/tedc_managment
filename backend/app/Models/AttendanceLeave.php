<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A late arrival, early leave or temporary leave recorded for an attendance. */
#[Fillable(['attendance_id', 'type', 'from_time', 'to_time', 'minutes', 'reason', 'attachments', 'entered_by', 'notified_at'])]
class AttendanceLeave extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['attachments' => 'array', 'notified_at' => 'datetime'];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
