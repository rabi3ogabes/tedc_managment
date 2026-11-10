<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_id', 'training_group_id', 'employee_id', 'registration_id', 'position', 'status', 'promoted_at'])]
class WaitingList extends Model
{
    use HasUuids;

    protected $casts = ['promoted_at' => 'datetime'];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
