<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One evaluation of the assistant against the golden question set. */
#[Fillable(['embedder', 'store', 'with_model', 'questions', 'answerable', 'off_topic', 'hit_at_3', 'hit_at_5', 'citation_accuracy', 'refusal_accuracy', 'false_refusals', 'passed', 'details', 'started_by'])]
class AiEvalRun extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['with_model' => 'boolean', 'questions' => 'integer', 'answerable' => 'integer', 'off_topic' => 'integer', 'hit_at_3' => 'float', 'hit_at_5' => 'float', 'citation_accuracy' => 'float',
            'refusal_accuracy' => 'float', 'false_refusals' => 'integer', 'passed' => 'boolean', 'details' => 'array'];
    }
}
