<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One legacy survey or form taken over by an engine survey, with the batch it belongs to so the batch can be rolled back. */
#[Fillable(['batch', 'legacy_type', 'legacy_id', 'survey_id', 'counts', 'created_by', 'created_at'])]
class SurveyMigration extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPES = ['needs_survey', 'evaluation_form'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'created_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
