<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['attempt_id', 'question_id', 'draft_text', 'suggested_score', 'max_points', 'rubric', 'source', 'status', 'final_comment', 'final_score', 'reviewed_by', 'reviewed_at'])]
class AiFeedbackDraft extends Model
{
    use HasUuids;

    protected $table = 'ai_feedback_drafts';

    protected $casts = ['rubric' => 'array', 'reviewed_at' => 'datetime'];
}
