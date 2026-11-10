<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['program_id', 'title_ar', 'title_en', 'description_ar', 'description_en', 'sort_order'])]
class CourseModule extends Model
{
    use HasTranslations, HasUuids;

    protected $casts = [];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(CourseLesson::class, 'module_id')->orderBy('sort_order');
    }
}
