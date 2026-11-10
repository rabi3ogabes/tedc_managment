<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A lifecycle event of a survey (published, closed, exported…). Response events never name the respondent. */
#[Fillable(['survey_id', 'actor_id', 'event', 'data', 'ip_address', 'created_at'])]
class SurveyEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
