<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A school taking part in a workshop: the host or a participating school, with how it was chosen and why. */
#[Fillable(['program_id', 'school_id', 'role', 'seats', 'status', 'suggestion_reasons', 'distance_km', 'excluded_reason', 'decided_by'])]
class PlcWorkshopSchool extends Model
{
    use HasUuids;

    public const ROLES = ['host', 'participant'];

    public const STATUSES = ['suggested', 'selected', 'excluded', 'approved'];

    protected function casts(): array
    {
        return ['suggestion_reasons' => 'array', 'distance_km' => 'float', 'seats' => 'integer'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(PlcWorkshop::class, 'program_id', 'program_id');
    }
}
