<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'field', 'kind', 'current_value', 'requested_value', 'note', 'status', 'applied', 'reviewed_by', 'reviewed_at', 'review_note'])]
class ProfileChangeRequest extends Model
{
    use HasUuids;

    protected $casts = ['applied' => 'boolean', 'reviewed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
