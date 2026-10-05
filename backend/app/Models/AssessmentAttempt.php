<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One try at an assessment: the drawn questions, the answers and the result. */
#[Fillable(['assessment_id', 'registration_id', 'attempt_no', 'started_at', 'submitted_at', 'expires_at', 'ip', 'device', 'delivery', 'questions', 'answers', 'auto_score', 'manual_score', 'max_score', 'score_percent', 'passed', 'status', 'integrity', 'graded_by', 'graded_at', 'feedback', 'extra_minutes', 'void_reason'])]
class AssessmentAttempt extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'submitted_at' => 'datetime', 'expires_at' => 'datetime', 'graded_at' => 'datetime', 'questions' => 'array', 'answers' => 'array', 'integrity' => 'array', 'passed' => 'boolean', 'score_percent' => 'float', 'auto_score' => 'float', 'manual_score' => 'float', 'max_score' => 'float'];
    }
}
