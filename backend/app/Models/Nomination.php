<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_id', 'employee_id', 'school_id', 'nominated_by', 'nominator_type', 'justification', 'status', 'decided_at'])]
class Nomination extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['decided_at' => 'datetime'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function nominator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominated_by');
    }
}
