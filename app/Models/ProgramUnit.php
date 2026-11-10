<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A unit of a program: title, objectives, hours and the competencies it builds. */
#[Fillable(['program_id', 'sort_order', 'title_ar', 'title_en', 'objectives', 'hours', 'summary_ar', 'summary_en'])]
class ProgramUnit extends Model
{
    use HasTranslations, HasUuids;

    protected function casts(): array
    {
        return ['objectives' => 'array', 'hours' => 'float'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'program_unit_skill');
    }
}
