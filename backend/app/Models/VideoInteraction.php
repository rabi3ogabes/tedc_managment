<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A question, reflection, note or checkpoint shown at a moment of a video. */
#[Fillable(['lesson_id', 'at_seconds', 'type', 'question_id', 'prompt_ar', 'prompt_en', 'required', 'blocks_progress', 'require_correct', 'allow_skip', 'sort_order'])]
class VideoInteraction extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['at_seconds' => 'float', 'required' => 'boolean', 'blocks_progress' => 'boolean', 'require_correct' => 'boolean', 'allow_skip' => 'boolean'];
    }
}
