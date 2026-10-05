<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A trainee's request to excuse an absence, decided by the direct manager. */
#[Fillable(['registration_id', 'session_id', 'employee_id', 'reason_code', 'reason_text', 'attachments', 'from_date', 'to_date', 'status', 'manager_id', 'decided_at', 'decision_note'])]
class AbsenceExcuse extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['attachments' => 'array', 'from_date' => 'date', 'to_date' => 'date', 'decided_at' => 'datetime'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'session_id');
    }
}
