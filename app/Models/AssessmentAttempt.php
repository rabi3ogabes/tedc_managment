<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One try at an assessment: the drawn questions, the answers and the result. */
#[Fillable(['assessment_id', 'registration_id', 'attempt_no', 'started_at', 'submitted_at', 'expires_at', 'ip', 'device', 'delivery', 'questions', 'answers', 'auto_score', 'manual_score', 'max_score', 'score_percent', 'passed', 'status', 'integrity', 'graded_by', 'graded_at', 'feedback', 'extra_minutes', 'void_reason', 'device_elapsed_seconds', 'client_started_at', 'adaptive_log'])]
class AssessmentAttempt extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'submitted_at' => 'datetime', 'expires_at' => 'datetime', 'graded_at' => 'datetime', 'questions' => 'array', 'answers' => 'array', 'integrity' => 'array', 'passed' => 'boolean', 'score_percent' => 'float', 'auto_score' => 'float', 'manual_score' => 'float', 'max_score' => 'float', 'device_elapsed_seconds' => 'integer', 'client_started_at' => 'datetime', 'adaptive_log' => 'array'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** The audit log keeps the steps of an adaptive trail (question, level, result), not its pool of candidate questions. */
    public function auditValues(array $values): array
    {
        if (isset($values['adaptive_log'])) {
            $log = is_string($values['adaptive_log']) ? json_decode($values['adaptive_log'], true) : $values['adaptive_log'];
            $values['adaptive_log'] = ['steps' => array_map(fn ($s) => [$s['question_id'] ?? null, $s['level'] ?? null, $s['correct'] ?? null], (array) ($log['steps'] ?? []))];
        }

        return $values;
    }
}
