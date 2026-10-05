<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['training_group_id', 'program_session_id', 'registration_id', 'employee_id', 'check_in_at', 'check_out_at', 'method', 'status', 'minutes_attended', 'device_info', 'ip_address', 'recorded_by', 'latitude', 'longitude', 'accuracy_m', 'distance_m', 'location_status', 'join_count', 'last_join_at', 'biometric_verified', 'signature_path', 'device_id', 'excuse_id', 'leave_minutes', 'left_early_at', 'notes'])]
class Attendance extends Model
{
    use HasUuids;

    protected $table = 'attendance';

    protected $casts = ['check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'last_join_at' => 'datetime', 'minutes_attended' => 'integer', 'latitude' => 'float', 'longitude' => 'float', 'accuracy_m' => 'integer', 'distance_m' => 'integer', 'left_early_at' => 'datetime', 'leave_minutes' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (Attendance $attendance) {
            $attendance->training_group_id ??= ProgramSession::whereKey($attendance->program_session_id)->value('training_group_id');
        });
    }

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
