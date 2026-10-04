<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A training kit (الحقيبة التدريبية): everything a trainer needs to deliver a program. */
#[Fillable(['code', 'title_ar', 'title_en', 'description_ar', 'description_en', 'program_id', 'category_id', 'delivery', 'status', 'audience', 'duration_hours', 'objectives', 'tags', 'cover_path',
    'version', 'review_round', 'owner_id', 'due_at', 'submitted_at', 'approved_at', 'published_at', 'approved_by', 'created_by'])]
class TrainingKit extends Model
{
    use Auditable, HasTranslations, HasUuids;

    public const DRAFT = 'draft';

    public const IN_DEVELOPMENT = 'in_development';

    public const IN_REVIEW = 'in_review';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const APPROVED = 'approved';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    /** The kind of program the kit is for — always the same as its program's delivery mode when it has one. */
    public const DELIVERIES = ['in_person', 'online', 'hybrid'];

    public const STATUSES = [self::DRAFT, self::IN_DEVELOPMENT, self::IN_REVIEW, self::CHANGES_REQUESTED, self::APPROVED, self::PUBLISHED, self::ARCHIVED];

    /** Statuses in which the content may still be edited. */
    public const EDITABLE = [self::DRAFT, self::IN_DEVELOPMENT, self::CHANGES_REQUESTED, self::IN_REVIEW];

    protected $casts = [
        'objectives' => 'array', 'tags' => 'array', 'duration_hours' => 'float',
        'due_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(KitMember::class, 'kit_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(KitFile::class, 'kit_id')->orderBy('sort_order')->orderBy('created_at');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(KitAsset::class, 'kit_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(KitComment::class, 'kit_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(KitReview::class, 'kit_id')->orderByDesc('round');
    }

    public function activity(): HasMany
    {
        return $this->hasMany(KitActivity::class, 'kit_id')->latest('created_at');
    }
}
