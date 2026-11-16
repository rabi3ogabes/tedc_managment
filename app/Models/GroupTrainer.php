<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A trainer proposed for a group: fills the assignment form, then leadership approves (after the competent authority). */
#[Fillable(['group_id', 'trainer_id', 'role', 'hours', 'status', 'form', 'form_submitted_at', 'proposed_by', 'decided_by', 'decided_at', 'decision_note', 'external_approval_ref', 'external_approval_path'])]
class GroupTrainer extends Model
{
    use HasUuids;

    public const PROPOSED = 'proposed';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['form' => 'array', 'form_submitted_at' => 'datetime', 'decided_at' => 'datetime', 'hours' => 'float'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'group_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
