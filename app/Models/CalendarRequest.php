<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** «طلب حجز في يوم إجازة»: someone asks to train on a closed day; whoever approves the calendar decides, and an approval opens the day. */
#[Fillable(['date', 'program_id', 'training_group_id', 'day_idx', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'])]
class CalendarRequest extends Model
{
    use Auditable, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $casts = ['date' => 'date:Y-m-d', 'decided_at' => 'datetime', 'day_idx' => 'integer'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function trainingGroup(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
