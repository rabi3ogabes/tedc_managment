<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tick on a system's go-live checklist: who did it and when. */
#[Fillable(['integration_key', 'item', 'done_by', 'done_at', 'note'])]
class IntegrationChecklistItem extends Model
{
    use HasUuids;

    protected $casts = ['done_at' => 'datetime'];

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
