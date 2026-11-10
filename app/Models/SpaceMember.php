<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['space_id', 'user_id', 'role', 'status', 'notify', 'source', 'joined_at'])]
class SpaceMember extends Model
{
    use HasUuids;

    protected $casts = ['joined_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id');
    }
}
