<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person nominated for a workshop, with the explicit states the Ministry's procedure names. */
#[Fillable(['program_id', 'employee_id', 'school_id', 'status', 'source', 'nominated_by', 'decided_by', 'decided_at', 'reason', 'note', 'clashes', 'registration_id'])]
class PlcNomination extends Model
{
    use HasUuids;

    public const STATUSES = ['nominated', 'approved', 'waitlisted', 'rejected', 'declined', 'withdrawn', 'completed'];

    public const TRANSITIONS = [
        'nominated' => ['approved', 'waitlisted', 'rejected', 'declined', 'withdrawn'],
        'waitlisted' => ['approved', 'nominated', 'rejected', 'declined', 'withdrawn'],
        'approved' => ['withdrawn', 'declined', 'completed'],
        'rejected' => ['nominated'],
        'declined' => ['nominated'],
        'withdrawn' => ['nominated'],
        'completed' => [],
    ];

    public const REASON_REQUIRED = ['rejected', 'declined', 'withdrawn'];

    /** States that hold a seat. */
    public const SEATED = ['nominated', 'approved', 'completed'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'clashes' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function nominator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominated_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
