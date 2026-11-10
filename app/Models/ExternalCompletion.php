<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['registration_id', 'evidence', 'note', 'source', 'status', 'reviewer_id', 'review_note', 'decided_at'])]
class ExternalCompletion extends Model
{
    use HasUuids;

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    protected $casts = ['evidence' => 'array', 'decided_at' => 'datetime'];
}
