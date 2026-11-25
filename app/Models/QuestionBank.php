<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reusable bank of questions, shared by programs or private to its author. */
#[Fillable(['title_ar', 'title_en', 'program_id', 'owner_id', 'visibility', 'description'])]
class QuestionBank extends Model
{
    use Auditable, HasUuids;

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'bank_id');
    }
}
