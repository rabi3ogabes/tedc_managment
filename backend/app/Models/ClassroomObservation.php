<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A classroom observation with competency scores. */
#[Fillable(['employee_id', 'observer_user_id', 'observer_role', 'observed_on', 'subject', 'grade', 'scores', 'overall', 'notes', 'source'])]
class ClassroomObservation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['scores' => 'array', 'observed_on' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
