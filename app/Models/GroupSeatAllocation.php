<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Seats of a group reserved for one entity (school, department, school group, job group) or for the open pool. */
#[Fillable(['group_id', 'entity_type', 'entity_id', 'seats', 'release_at', 'released_at', 'priority'])]
class GroupSeatAllocation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['release_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'group_id');
    }
}
