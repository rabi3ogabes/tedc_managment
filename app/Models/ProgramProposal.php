<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A department / school proposal for a specialised program in a cycle. */
#[Fillable(['cycle_id', 'entity_type', 'entity_id', 'entity_name', 'submitted_by', 'program_title_ar', 'program_title_en', 'existing_program_id', 'groups_count', 'axes', 'target_job_title_ids', 'target_description', 'days', 'hours', 'kit_availability', 'kit_attachment_path', 'trainer_nominations', 'importance', 'priority_rank', 'justification', 'status', 'reviewer_id', 'review_note', 'plan_item_id'])]
class ProgramProposal extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['axes' => 'array', 'target_job_title_ids' => 'array', 'trainer_nominations' => 'array', 'hours' => 'float'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(NeedsCycle::class, 'cycle_id');
    }
}
