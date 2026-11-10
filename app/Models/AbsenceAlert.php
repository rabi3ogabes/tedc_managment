<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An absence threshold already announced (once per level). */
#[Fillable(['registration_id', 'level', 'absence_percent', 'supervisor_note', 'notified_at'])]
class AbsenceAlert extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['absence_percent' => 'float', 'notified_at' => 'datetime'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
