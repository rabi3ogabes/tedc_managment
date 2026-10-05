<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Points a grader gave one answer. */
#[Fillable(['attempt_id', 'question_id', 'points_awarded', 'max_points', 'grader_id', 'comment'])]
class AttemptAnswerGrade extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['points_awarded' => 'float', 'max_points' => 'float'];
    }
}
