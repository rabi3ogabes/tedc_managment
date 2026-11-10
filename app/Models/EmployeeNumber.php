<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee number a person has had (a re-hired employee gets a new one each time); the current one is also on the employee. */
#[Fillable(['employee_id', 'employee_no', 'seq', 'appointed_on', 'employment_class', 'org_name', 'is_current', 'source_system'])]
class EmployeeNumber extends Model
{
    use HasUuids;

    protected $casts = ['appointed_on' => 'date', 'is_current' => 'boolean', 'seq' => 'integer'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
