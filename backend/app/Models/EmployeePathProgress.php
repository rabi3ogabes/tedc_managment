<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'path_id', 'current_level_no', 'target_level_no', 'status', 'explanation', 'evaluated_at'])]
class EmployeePathProgress extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['explanation' => 'array', 'evaluated_at' => 'datetime'];

    protected $table = 'employee_path_progress';

    public function path(): BelongsTo
    {
        return $this->belongsTo(CareerPath::class, 'path_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
