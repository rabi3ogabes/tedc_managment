<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A block of an assessment with fixed questions or a random draw. */
#[Fillable(['assessment_id', 'title', 'sort_order', 'selection', 'bank_id', 'category_ids', 'difficulty_mix', 'count', 'points_per_question', 'question_ids'])]
class AssessmentSection extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['category_ids' => 'array', 'difficulty_mix' => 'array', 'question_ids' => 'array', 'points_per_question' => 'float'];
    }
}
