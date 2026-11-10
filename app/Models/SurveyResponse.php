<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['lesson_id', 'registration_id', 'answers', 'submitted_at'])]
class SurveyResponse extends Model
{
    use HasUuids;

    protected $casts = ['answers' => 'array', 'submitted_at' => 'datetime'];
}
