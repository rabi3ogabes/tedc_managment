<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A direct manager's request for a program for their staff. */
#[Fillable(['cycle_id', 'requested_by', 'entity_id', 'entity_name', 'program_id', 'title', 'need_degree', 'objectives', 'employee_ids', 'preferred_window', 'status', 'review_note', 'reviewer_id', 'plan_item_id'])]
class InstitutionalRequest extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['objectives' => 'array', 'employee_ids' => 'array'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(NeedsCycle::class, 'cycle_id');
    }
}
