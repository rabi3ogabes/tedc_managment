<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'program_id', 'employee_id', 'nomination_id', 'source', 'status', 'eligibility_snapshot', 'approved_by', 'approved_at',
    'completed_at', 'attendance_percent', 'tasks_completed', 'evaluation_completed', 'certificate_status', 'impact_score', 'notes',
])]
class Registration extends Model
{
    use Auditable, HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    /** Registrations that occupy a seat in the program. */
    public const SEAT_HOLDING = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_COMPLETED];

    public const SOURCE_SELF = 'self';

    public const SOURCE_SCHOOL = 'school_nomination';

    public const SOURCE_CENTER = 'center_nomination';

    public const SOURCE_BULK = 'bulk_import';

    protected function casts(): array
    {
        return [
            'eligibility_snapshot' => 'array',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'attendance_percent' => 'float',
            'impact_score' => 'float',
            'tasks_completed' => 'boolean',
            'evaluation_completed' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function nomination(): BelongsTo
    {
        return $this->belongsTo(Nomination::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(Evaluation::class);
    }

    public function supervisorEvaluations(): HasMany
    {
        return $this->hasMany(SupervisorEvaluation::class);
    }

    public function impactSurveys(): HasMany
    {
        return $this->hasMany(ImpactSurvey::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }
}
