<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['program_id', 'group_id', 'interviewee_user_id', 'interviewee_name', 'interviewer_id', 'held_at', 'method', 'questions_answers', 'summary', 'attachments', 'sentiment'])]
class EvaluationInterview extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['held_at' => 'datetime', 'questions_answers' => 'array', 'attachments' => 'array'];
}
