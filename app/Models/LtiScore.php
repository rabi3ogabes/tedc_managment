<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tool_id', 'lesson_id', 'user_id', 'score_given', 'score_max', 'activity_progress', 'grading_progress'])]
class LtiScore extends Model
{
    use HasUuids;

    protected $casts = ['score_given' => 'float', 'score_max' => 'float'];
}
