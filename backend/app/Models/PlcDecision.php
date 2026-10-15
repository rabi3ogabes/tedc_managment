<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The decision log: why a state changed, a school or trainer was chosen, or an exception was granted. */
#[Fillable(['subject_type', 'subject_id', 'kind', 'decision', 'reason', 'data', 'decided_by', 'created_at'])]
class PlcDecision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
