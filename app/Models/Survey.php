<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A survey definition (Survey Studio v2). `questions` is the working draft; every publish freezes a
 * {@see SurveyVersion} that submissions are bound to. The privacy mode decides what a response may carry.
 */
#[Fillable(['kind', 'title_ar', 'title_en', 'description_ar', 'description_en', 'status', 'privacy_mode', 'owner_id', 'program_id', 'current_version_id',
    'questions', 'audience', 'settings', 'approved_checksum', 'review_note', 'reviewed_by', 'reviewed_at', 'opens_at', 'closes_at', 'timezone', 'retention_days',
    'pseudonym_key', 'published_at', 'closed_at', 'archived_at', 'legacy_type', 'legacy_id', 'legal_hold', 'aggregates', 'answers_purged_at', 'key_deleted_at'])]
class Survey extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUSES = ['draft', 'in_review', 'approved', 'published', 'closed', 'archived'];

    public const KINDS = ['general', 'needs', 'satisfaction', 'impact_trainee', 'impact_manager', 'trainer_reflection', 'planning_evaluation', 'supervisor_feedback', 'specialist_feedback', 'custom'];

    public const PRIVACY_MODES = ['identified', 'confidential', 'anonymous'];

    public const EDIT_POLICIES = ['until_close', 'never'];

    protected $hidden = ['pseudonym_key'];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'audience' => 'array',
            'settings' => 'array',
            'pseudonym_key' => 'encrypted',
            'reviewed_at' => 'datetime',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
            'retention_days' => 'integer',
            'legal_hold' => 'boolean',
            'aggregates' => 'array',
            'answers_purged_at' => 'datetime',
            'key_deleted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SurveyVersion::class)->orderByDesc('number');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SurveyParticipant::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SurveySubmission::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(SurveySession::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(SurveyDistribution::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SurveyEvent::class)->orderByDesc('created_at');
    }

    /** Published, past its opening time (when set) and before its closing time (when set). */
    public function isOpen(): bool
    {
        return $this->status === 'published'
            && (! $this->opens_at || $this->opens_at->isPast())
            && (! $this->closes_at || $this->closes_at->isFuture());
    }

    public function isAnonymous(): bool
    {
        return $this->privacy_mode === 'anonymous';
    }

    public function isConfidential(): bool
    {
        return $this->privacy_mode === 'confidential';
    }

    public function isIdentified(): bool
    {
        return $this->privacy_mode === 'identified';
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function editPolicy(): string
    {
        return in_array($this->setting('edit_policy'), self::EDIT_POLICIES, true) ? $this->setting('edit_policy') : 'until_close';
    }

    public function title(?string $locale = null): string
    {
        return (($locale ?? app()->getLocale()) === 'en' && $this->title_en) ? $this->title_en : $this->title_ar;
    }
}
