<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kit_id', 'round', 'status', 'submitted_by', 'decided_by', 'submitted_at', 'decided_at', 'note', 'summary'])]
class KitReview extends Model
{
    use HasUuids;

    protected $casts = ['summary' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(TrainingKit::class, 'kit_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
