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
    'objectives', 'delivery_mode', 'level', 'total_hours', 'capacity', 'min_attendance_percent', 'require_biometric', 'requires_tasks',
    'requires_evaluation', 'start_date', 'end_date', 'registration_opens_at', 'registration_closes_at',
    'registration_modes', 'status', 'cover_path', 'is_featured', 'created_by', 'audience', 'source_type',
    'survey_mode', 'survey_auto_hours', 'survey_opened_at', 'survey_closed_at',
    'parent_id', 'kind', 'axes', 'is_emergency', 'emergency_reason', 'owner_type', 'owner_school_id', 'approval_status',
    'remote', 'coordinator_id', 'certificate_template_id', 'trainer_certificate_template_id', 'has_course', 'course_sequential', 'course_completion_percent', 'course_auto_certificate',
])]
class Program extends Model
{
    protected static function booted(): void
    {
        // Every program has at least one group (its first run); a program edited as a whole keeps a single group in step.
        static::created(fn (Program $program) => TrainingGroup::create([
            'program_id' => $program->id, 'code' => $program->code.'-G1', 'sequence' => 1, 'delivery_mode' => TrainingGroup::modeFor($program->delivery_mode ?? 'in_person'),
            'start_date' => $program->start_date, 'end_date' => $program->end_date, 'registration_opens_at' => $program->registration_opens_at, 'registration_closes_at' => $program->registration_closes_at,
            'capacity' => $program->capacity ?? 30, 'min_attendance_percent' => $program->min_attendance_percent, 'status' => TrainingGroup::statusFor($program->status ?? self::STATUS_DRAFT),
            'is_emergency' => (bool) $program->is_emergency, 'supervisor_id' => $program->coordinator_id,
            'published_at' => ($program->status ?? self::STATUS_DRAFT) === self::STATUS_DRAFT ? null : now(),
        ]));
        static::saved(function (Program $program) {
            if (! $program->wasChanged(['start_date', 'end_date', 'registration_opens_at', 'registration_closes_at', 'capacity', 'min_attendance_percent', 'delivery_mode', 'status', 'coordinator_id', 'is_emergency'])) {
                return;
            }
            $groups = $program->groups()->get();
            if ($groups->count() !== 1) {
                return;
            }
            $groups->first()->update([
                'start_date' => $program->start_date, 'end_date' => $program->end_date, 'registration_opens_at' => $program->registration_opens_at, 'registration_closes_at' => $program->registration_closes_at,
                'capacity' => $program->capacity, 'min_attendance_percent' => $program->min_attendance_percent, 'delivery_mode' => TrainingGroup::modeFor($program->delivery_mode ?? 'in_person'),
                'status' => TrainingGroup::statusFor($program->status ?? self::STATUS_DRAFT), 'supervisor_id' => $program->coordinator_id, 'is_emergency' => (bool) $program->is_emergency,
                'published_at' => $program->status === self::STATUS_DRAFT ? null : ($groups->first()->published_at ?? now()),
            ]);
        });
        // Changing how a program is delivered changes the type of its kits with it.
        static::saved(function (Program $program) {
            if ($program->wasChanged('delivery_mode') && in_array($program->delivery_mode, TrainingKit::DELIVERIES, true)) {
                TrainingKit::where('program_id', $program->id)->update(['delivery' => $program->delivery_mode]);
            }
        });
    }

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
            'axes' => 'array',
            'is_emergency' => 'boolean',
            'audience' => 'array',
            'remote' => 'array',
            'has_course' => 'boolean',
            'course_sequential' => 'boolean',
            'course_auto_certificate' => 'boolean',
            'registration_modes' => 'array',
            'require_biometric' => 'boolean',
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
        return $query->whereIn('status', self::VISIBLE)->where('code', 'not like', 'TEST-%')->where('owner_type', 'center');
    }

    public function ownerSchool(): BelongsTo
    {
        return $this->belongsTo(School::class, 'owner_school_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Sub-programs (one level only). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(TrainingGroup::class)->orderBy('sequence');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProgramUnit::class)->orderBy('sort_order');
    }

    /** The group a registration goes to when none is chosen: the first one open for registration, else the first. */
    public function primaryGroup(): ?TrainingGroup
    {
        $groups = $this->groups()->get();

        return $groups->first(fn (TrainingGroup $g) => $g->isRegistrationOpen()) ?? $groups->first();
    }

    public function hasSingleGroup(): bool
    {
        return $this->groups()->count() <= 1;
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
