<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One employee's development need for one competency, approved by their manager. */
#[Fillable(['employee_id', 'skill_id', 'source', 'source_ref', 'current_level', 'required_level', 'gap', 'priority_score', 'explanation_ar', 'explanation_en', 'status', 'manager_id', 'manager_note', 'decided_at', 'cycle_id'])]
class IndividualNeed extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['source_ref' => 'array', 'decided_at' => 'datetime', 'priority_score' => 'float'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
