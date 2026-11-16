<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A scan that did not record attendance, kept for the administrator. */
#[Fillable(['employee_id', 'program_id', 'program_session_id', 'outcome', 'code', 'message', 'device_info', 'ip_address'])]
class AttendanceAttempt extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'program_session_id');
    }
}
