<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['registration_id', 'program_id', 'employee_id', 'stage_days', 'scheduled_for', 'sent_at', 'completed_at', 'status', 'applied_learning', 'application_score', 'changes_observed', 'skills_improved', 'needs_support', 'support_details', 'evidence'])]
class ImpactSurvey extends Model
{
    use HasUuids;

    public const STAGES = [30, 60, 90];

    protected $casts = [
        'evidence' => 'array',
        'scheduled_for' => 'date', 'sent_at' => 'datetime', 'completed_at' => 'datetime',
        'skills_improved' => 'array', 'needs_support' => 'boolean', 'application_score' => 'integer', 'stage_days' => 'integer',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
