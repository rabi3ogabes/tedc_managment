<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['lesson_id', 'type', 'text_ar', 'text_en', 'options', 'points', 'explanation_ar', 'explanation_en', 'sort_order'])]
class QuizQuestion extends Model
{
    use HasTranslations, HasUuids;

    protected $casts = ['options' => 'array', 'points' => 'float'];
}
