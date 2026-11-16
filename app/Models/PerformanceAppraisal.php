<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Yearly appraisal rating of an employee (imported or from the HR connector). */
#[Fillable(['employee_id', 'year', 'rating_code', 'score', 'source', 'imported_at'])]
class PerformanceAppraisal extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
