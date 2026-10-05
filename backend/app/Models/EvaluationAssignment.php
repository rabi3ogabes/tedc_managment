<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['form_id', 'program_id', 'group_id', 'respondent_type', 'respondent_user_id', 'subject_registration_id', 'due_at', 'sent_at', 'reminded_at', 'status', 'assigned_by'])]
class EvaluationAssignment extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['due_at' => 'datetime', 'sent_at' => 'datetime', 'reminded_at' => 'datetime'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(EvaluationForm::class, 'form_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_user_id');
    }

    public function response(): HasOne
    {
        return $this->hasOne(EvaluationResponse::class, 'assignment_id');
    }
}
