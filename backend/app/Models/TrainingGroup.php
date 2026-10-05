<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A training group (دورة تدريبية): one run of a program with its own dates, seats, supervisor, trainers and status.
 * A program has one or more groups; the program is the offer, the group is the delivery.
 */
#[Fillable([
    'program_id', 'code', 'title_ar', 'title_en', 'sequence', 'delivery_mode', 'start_date', 'end_date', 'registration_opens_at', 'registration_closes_at',
    'capacity', 'min_attendance_percent', 'supervisor_id', 'default_room_id', 'status', 'status_reason', 'postponed_to', 'plan_item_id', 'is_emergency', 'published_at', 'approval_mode', 'approve_after_window', 'allow_overlap_until_approved',
])]
class TrainingGroup extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    public const PLANNED = 'planned';

    public const REGISTRATION_OPEN = 'registration_open';

    public const ONGOING = 'ongoing';

    public const INCOMPLETE = 'incomplete';

    public const POSTPONED = 'postponed';

    public const CANCELLED = 'cancelled';

    public const COMPLETED = 'completed';

    public const STATUSES = [self::PLANNED, self::REGISTRATION_OPEN, self::ONGOING, self::INCOMPLETE, self::POSTPONED, self::CANCELLED, self::COMPLETED];

    public const MODES = ['in_person', 'online', 'blended', 'self_paced'];

    /** Allowed status changes (a manual change by the supervisor; dates move groups along automatically too). */
    public const TRANSITIONS = [
        self::PLANNED => [self::REGISTRATION_OPEN, self::ONGOING, self::POSTPONED, self::CANCELLED],
        self::REGISTRATION_OPEN => [self::PLANNED, self::ONGOING, self::POSTPONED, self::CANCELLED],
        self::ONGOING => [self::COMPLETED, self::INCOMPLETE, self::POSTPONED, self::CANCELLED],
        self::INCOMPLETE => [self::ONGOING, self::COMPLETED, self::CANCELLED],
        self::POSTPONED => [self::PLANNED, self::REGISTRATION_OPEN, self::CANCELLED],
        self::CANCELLED => [self::PLANNED],
        self::COMPLETED => [],
    ];

    /** A reason is mandatory for these. */
    public const REASON_REQUIRED = [self::POSTPONED, self::CANCELLED, self::INCOMPLETE];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'postponed_to' => 'date', 'published_at' => 'datetime',
            'registration_opens_at' => 'datetime', 'registration_closes_at' => 'datetime', 'is_emergency' => 'boolean', 'approve_after_window' => 'boolean', 'allow_overlap_until_approved' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(TrainingRoom::class, 'default_room_id');
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanItem::class, 'plan_item_id');
    }

    public function trainers(): HasMany
    {
        return $this->hasMany(GroupTrainer::class, 'group_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ProgramSession::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /** The title shown for the group: its own, else the program's, with the run number when the program has several. */
    public function displayTitle(?string $locale = null): string
    {
        $ar = ($locale ?? app()->getLocale()) === 'ar';
        $own = $ar ? $this->title_ar : $this->title_en;
        if (filled($own)) {
            return $own;
        }

        return ($ar ? $this->program->title_ar : $this->program->title_en).($this->sequence > 1 ? ' — '.$this->sequence : '');
    }

    public function seatsTaken(): int
    {
        return $this->registrations()->whereIn('status', Registration::SEAT_HOLDING)->count();
    }

    public function seatsAvailable(): int
    {
        return max(0, $this->capacity - $this->seatsTaken());
    }

    public function isRegistrationOpen(): bool
    {
        if (! in_array($this->status, [self::PLANNED, self::REGISTRATION_OPEN], true) || $this->published_at === null) {
            return false;
        }
        $now = now();

        return (! $this->registration_opens_at || $this->registration_opens_at->lte($now)) && (! $this->registration_closes_at || $this->registration_closes_at->gte($now));
    }

    /** How a program's delivery maps to a group's. */
    public static function modeFor(string $programMode): string
    {
        return ['in_person' => 'in_person', 'online' => 'online', 'hybrid' => 'blended'][$programMode] ?? 'in_person';
    }

    /** How a program's status maps to its (only) group's. */
    public static function statusFor(string $programStatus): string
    {
        return [
            Program::STATUS_DRAFT => self::PLANNED, Program::STATUS_PUBLISHED => self::PLANNED, Program::STATUS_REGISTRATION_OPEN => self::REGISTRATION_OPEN,
            Program::STATUS_IN_PROGRESS => self::ONGOING, Program::STATUS_COMPLETED => self::COMPLETED, Program::STATUS_ARCHIVED => self::COMPLETED, Program::STATUS_CANCELLED => self::CANCELLED,
        ][$programStatus] ?? self::PLANNED;
    }
}
