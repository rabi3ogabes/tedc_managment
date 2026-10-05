<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A test: final, quiz, diagnostic, pre/post, comprehensive or practice. */
#[Fillable(['program_id', 'group_id', 'lesson_id', 'kind', 'title_ar', 'title_en', 'instructions_ar', 'instructions_en', 'delivery', 'access_code_mode', 'time_limit_minutes', 'window_opens_at', 'window_closes_at', 'max_attempts', 'attempt_cooldown_hours', 'pass_percent', 'weight_in_course', 'shuffle_questions', 'shuffle_options', 'feedback_mode', 'show_score', 'show_correct_answers', 'require_restudy_on_fail', 'proctoring', 'status', 'released_at'])]
class Assessment extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['window_opens_at' => 'datetime', 'window_closes_at' => 'datetime', 'pass_percent' => 'float', 'weight_in_course' => 'float', 'shuffle_questions' => 'boolean', 'shuffle_options' => 'boolean', 'show_score' => 'boolean', 'show_correct_answers' => 'boolean', 'require_restudy_on_fail' => 'boolean', 'proctoring' => 'array', 'released_at' => 'datetime'];
    }
}
