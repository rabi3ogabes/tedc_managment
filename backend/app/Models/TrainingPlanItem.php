<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'plan_id', 'program_id', 'title_ar', 'title_en', 'category_id', 'audience', 'priority', 'priority_score', 'planned_groups', 'planned_seats', 'planned_hours',
    'window_start', 'window_end', 'source', 'source_refs', 'status', 'is_emergency', 'rationale_ar', 'rationale_en', 'review_comment',
])]
class TrainingPlanItem extends Model
{
    use HasTranslations, HasUuids;

    protected function casts(): array
    {
        return [
            'audience' => 'array', 'source_refs' => 'array', 'window_start' => 'date', 'window_end' => 'date', 'is_emergency' => 'boolean',
            'priority_score' => 'float', 'planned_hours' => 'float',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TrainingPlan::class, 'plan_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(TrainingGroup::class, 'plan_item_id');
    }
}
