<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['registration_id', 'employee_id', 'due_on', 'delivered_on', 'hours', 'beneficiary_count', 'beneficiaries', 'method', 'evidence', 'status', 'reviewer_id', 'note', 'reminded_at'])]
class KnowledgeTransfer extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['due_on' => 'date', 'delivered_on' => 'date', 'beneficiaries' => 'array', 'evidence' => 'array', 'hours' => 'float', 'reminded_at' => 'datetime'];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
