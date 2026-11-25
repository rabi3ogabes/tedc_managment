<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A question of any type; editing an active question creates a new version. */
#[Fillable(['bank_id', 'category_id', 'root_id', 'type', 'stem_ar', 'stem_en', 'media', 'payload', 'points', 'difficulty', 'skill_ids', 'explanation_ar', 'explanation_en', 'tags', 'version', 'status', 'author_id', 'content_hash'])]
class Question extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['media' => 'array', 'payload' => 'array', 'skill_ids' => 'array', 'tags' => 'array', 'points' => 'float'];
    }
}
