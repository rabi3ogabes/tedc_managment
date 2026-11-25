<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A right on one program given to a person who does not hold it through their role (attendance, notifications…). */
#[Fillable(['program_id', 'user_id', 'ability', 'granted_by', 'expires_at'])]
class ProgramGrant extends Model
{
    use HasUuids;

    public const ABILITIES = ['attendance.mark', 'notifications.send', 'kits.assign', 'tasks.review'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
