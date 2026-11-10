<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'registration_id', 'employee_id', 'text_response', 'file_path', 'file_name', 'mime', 'status', 'feedback', 'reviewed_by', 'reviewed_at', 'version', 'trainer_decision', 'trainer_id', 'trainer_decided_at', 'supervisor_decision', 'supervisor_id', 'supervisor_decided_at', 'returned_count'])]
class TaskSubmission extends Model
{
    use Auditable, HasUuids;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PENDING_FINAL = 'pending_final';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CHANGES = 'changes_requested';

    protected $casts = ['reviewed_at' => 'datetime', 'trainer_decided_at' => 'datetime', 'supervisor_decided_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
