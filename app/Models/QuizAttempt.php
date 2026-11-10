<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['lesson_id', 'registration_id', 'answers', 'score_percent', 'passed', 'duration_seconds', 'started_at', 'submitted_at'])]
class QuizAttempt extends Model
{
    use HasUuids;

    protected $casts = ['answers' => 'array', 'score_percent' => 'float', 'passed' => 'boolean', 'started_at' => 'datetime', 'submitted_at' => 'datetime'];
}
