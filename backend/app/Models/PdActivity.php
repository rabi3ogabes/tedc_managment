<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'type_id', 'title', 'provider', 'domain', 'skill_ids', 'starts_on', 'ends_on', 'duration_hours', 'participation_level', 'location', 'evidence', 'computed_hours', 'approved_hours', 'status', 'manager_id', 'manager_note', 'decided_at', 'recognition_request'])]
class PdActivity extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['skill_ids' => 'array', 'evidence' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'decided_at' => 'datetime', 'duration_hours' => 'float', 'computed_hours' => 'float', 'approved_hours' => 'float', 'recognition_request' => 'boolean'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(PdActivityType::class, 'type_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
