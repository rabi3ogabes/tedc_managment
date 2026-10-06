<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'details', 'status', 'due_at', 'handled_by', 'resolution', 'completed_at'])]
class DataSubjectRequest extends Model
{
    use HasUuids;

    protected $table = 'data_subject_requests';

    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
