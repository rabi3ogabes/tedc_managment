<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'status', 'source', 'template_key', 'questions', 'audience', 'settings', 'created_by', 'published_at', 'closes_at', 'closed_at'])]
class NeedsSurvey extends Model
{
    use Auditable, HasUuids;

    public const STATUSES = ['draft', 'published', 'closed'];

    public const SOURCES = ['builder', 'template', 'import'];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'audience' => 'array',
            'settings' => 'array',
            'published_at' => 'datetime',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NeedsSurveyRecipient::class, 'survey_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(NeedsSurveyResponse::class, 'survey_id');
    }

    public function trainingNeeds(): HasMany
    {
        return $this->hasMany(TrainingNeed::class, 'survey_id');
    }

    /** Open for answers: published and not past its closing time. */
    public function isOpen(): bool
    {
        return $this->status === 'published' && (! $this->closes_at || $this->closes_at->isFuture());
    }

    public function isAnonymous(): bool
    {
        return (bool) ($this->settings['anonymous'] ?? false);
    }
}
