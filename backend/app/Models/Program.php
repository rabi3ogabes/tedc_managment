<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'category_id', 'title_en', 'title_ar', 'summary_en', 'summary_ar', 'description_en', 'description_ar',
    'objectives', 'delivery_mode', 'level', 'total_hours', 'capacity', 'min_attendance_percent', 'requires_tasks',
    'requires_evaluation', 'start_date', 'end_date', 'registration_opens_at', 'registration_closes_at',
    'registration_modes', 'status', 'cover_path', 'is_featured', 'created_by', 'audience', 'source_type',
    'survey_mode', 'survey_auto_hours', 'survey_opened_at', 'survey_closed_at',
    'remote', 'certificate_template_id', 'trainer_certificate_template_id', 'has_course', 'course_sequential', 'course_completion_percent', 'course_auto_certificate',
])]
class Program extends Model
{
    use Auditable, HasFactory, HasTranslations, HasUuids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REGISTRATION_OPEN = 'registration_open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_REGISTRATION_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_ARCHIVED, self::STATUS_CANCELLED];

    /** Statuses visible on the public website and to employees. */
    public const VISIBLE = [self::STATUS_PUBLISHED, self::STATUS_REGISTRATION_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED];

    public const MODES = ['self', 'school_nomination', 'center_nomination', 'bulk_import'];

    protected function casts(): array
    {
        return [
            'objectives' => 'array',
            'audience' => 'array',
            'remote' => 'array',
            'has_course' => 'boolean',
            'course_sequential' => 'boolean',
            'course_auto_certificate' => 'boolean',
            'registration_modes' => 'array',
            'requires_tasks' => 'boolean',
            'requires_evaluation' => 'boolean',
            'is_featured' => 'boolean',
            'survey_auto_hours' => 'integer',
            'survey_opened_at' => 'datetime',
            'survey_closed_at' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'total_hours' => 'float',
        ];
    }

    /** Programs shown on the public website (the internal TEST-* programs used to try the mobile app are never listed). */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', self::VISIBLE)->where('code', 'not like', 'TEST-%');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ProgramSession::class)->orderBy('starts_at');
    }

    public function trainers(): BelongsToMany
    {
        return $this->belongsToMany(Trainer::class)->withPivot('role');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)->withPivot('target_level');
    }

    public function targetGroups(): HasMany
    {
        return $this->hasMany(TargetGroup::class);
    }

    public function eligibilityRules(): HasMany
    {
        return $this->hasMany(EligibilityRule::class)->orderBy('sort_order');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function courseModules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order');
    }

    public function courseLessons(): HasMany
    {
        return $this->hasMany(CourseLesson::class)->orderBy('sort_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function allowsMode(string $mode): bool
    {
        return in_array($mode, $this->registration_modes ?? self::MODES, true);
    }

    public function isRegistrationOpen(): bool
    {
        if (! in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_REGISTRATION_OPEN], true)) {
            return false;
        }
        $now = now();

        return (! $this->registration_opens_at || $this->registration_opens_at->lte($now))
            && (! $this->registration_closes_at || $this->registration_closes_at->gte($now));
    }

    public function seatsTaken(): int
    {
        return $this->registrations()->whereIn('status', Registration::SEAT_HOLDING)->count();
    }

    public function seatsAvailable(): int
    {
        return max(0, $this->capacity - $this->seatsTaken());
    }

    /** Whether trainees can fill in the program survey (evaluation) right now. */
    public function surveyIsOpen(): bool
    {
        if (! in_array($this->survey_mode, ['manual', 'auto'], true)) {
            return true; // legacy behaviour: always available
        }

        return $this->survey_opened_at !== null && (! $this->survey_closed_at || $this->survey_closed_at->lt($this->survey_opened_at));
    }
}
