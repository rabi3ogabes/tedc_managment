<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request to leave a program after the seat was approved. */
#[Fillable(['registration_id', 'requested_by', 'reason_code', 'reason_text', 'attachments', 'timing', 'stage', 'status', 'manager_id', 'manager_decision', 'manager_note', 'supervisor_id', 'supervisor_decision', 'supervisor_note', 'is_late'])]
class WithdrawalRequest extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['attachments' => 'array', 'is_late' => 'boolean'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
