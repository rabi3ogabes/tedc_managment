<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An immutable published snapshot of a survey. Rows are only ever inserted. */
#[Fillable(['survey_id', 'number', 'questions', 'settings', 'checksum', 'published_by', 'published_at'])]
class SurveyVersion extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['questions' => 'array', 'settings' => 'array', 'number' => 'integer', 'published_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SurveySubmission::class, 'version_id');
    }

    /** Versions are append-only: updates are refused at the model level too. */
    protected static function booted(): void
    {
        static::updating(fn () => false);
    }
}
