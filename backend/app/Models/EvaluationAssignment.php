<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['form_id', 'program_id', 'group_id', 'respondent_type', 'respondent_user_id', 'subject_registration_id', 'due_at', 'sent_at', 'reminded_at', 'status', 'assigned_by', 'survey_id', 'submission_id', 'score', 'program_session_id', 'trainer_id', 'rule_key'])]
class EvaluationAssignment extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['due_at' => 'datetime', 'sent_at' => 'datetime', 'reminded_at' => 'datetime', 'score' => 'float'];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class, 'survey_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(SurveySubmission::class, 'submission_id');
    }

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

    /** PLC Phase 6: the session this assignment evaluates, when it targets one. */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'program_session_id');
    }

    /** PLC Phase 6: the trainer this assignment evaluates, when it targets one. */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'trainer_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'subject_registration_id');
    }
}
