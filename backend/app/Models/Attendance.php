<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_session_id', 'registration_id', 'employee_id', 'check_in_at', 'check_out_at', 'method', 'status', 'minutes_attended', 'device_info', 'ip_address', 'recorded_by'])]
class Attendance extends Model
{
    use HasUuids;

    protected $table = 'attendance';

    protected $casts = ['check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'minutes_attended' => 'integer'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'program_session_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
