<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One evaluation instrument of a programme: the trainee survey, the trainer's self-reflection, the pre-test or the post-test. */
#[Fillable(['program_id', 'kind', 'enabled', 'form_id', 'assessment_id', 'source_kit_id', 'meta', 'created_by'])]
class ProgramInstrument extends Model
{
    use HasUuids;

    public const KINDS = ['trainee_survey', 'trainer_reflection', 'pre_test', 'post_test'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'meta' => 'array'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(EvaluationForm::class, 'form_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(TrainingKit::class, 'source_kit_id');
    }
}
