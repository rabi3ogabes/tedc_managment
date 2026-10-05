<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TrainingGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Training groups: creating them (with calendar-aware sessions), copying, publishing, the status machine with its
 * notifications, and the lifecycle that moves groups along with the dates.
 */
class TrainingGroupService
{
    public function __construct(
        private readonly CalendarService $calendar,
        private readonly RoomService $rooms,
        private readonly TrainingDaySettings $day,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data  dates, capacity, window, supervisor, room, delivery_mode, title, is_emergency, publish, sessions pattern
     * @return array{group: TrainingGroup, skipped: list<array<string, mixed>>}
     */
    public function create(Program $program, array $data, User $by): array
    {
        return DB::transaction(function () use ($program, $data) {
            $sequence = (int) $program->groups()->withTrashed()->max('sequence') + 1;
            $pattern = $data['sessions'] ?? null;
            if ($pattern !== null) {
                $pattern['start_date'] ??= $data['start_date'] ?? null;
                if (blank($pattern['start_date'])) {
                    throw new BusinessRuleException(__('messages.groups.pattern_start'), 'pattern_start_required');
                }
            }
            $candidates = $pattern ? $this->candidates($pattern) : [];
            $start = $data['start_date'] ?? ($candidates[0]['date'] ?? null);
            $end = $data['end_date'] ?? (end($candidates)['date'] ?? null);
            if (! empty($data['is_emergency']) && blank($data['emergency_reason'] ?? null) && blank($data['status_reason'] ?? null)) {
                throw new BusinessRuleException(__('messages.groups.emergency_reason'), 'emergency_reason_required');
            }

            $group = TrainingGroup::create([
                'program_id' => $program->id, 'code' => $program->code.'-G'.$sequence, 'sequence' => $sequence,
                'title_ar' => $data['title_ar'] ?? null, 'title_en' => $data['title_en'] ?? null,
                'delivery_mode' => $data['delivery_mode'] ?? TrainingGroup::modeFor($program->delivery_mode), 'start_date' => $start, 'end_date' => $end,
                'registration_opens_at' => $data['registration_opens_at'] ?? null, 'registration_closes_at' => $data['registration_closes_at'] ?? null,
                'capacity' => $data['capacity'] ?? $program->capacity, 'min_attendance_percent' => $data['min_attendance_percent'] ?? $program->min_attendance_percent,
                'supervisor_id' => $data['supervisor_id'] ?? $program->coordinator_id, 'default_room_id' => $data['default_room_id'] ?? null,
                'status' => TrainingGroup::PLANNED, 'is_emergency' => (bool) ($data['is_emergency'] ?? false), 'plan_item_id' => $data['plan_item_id'] ?? null,
                'published_at' => ! empty($data['publish']) ? now() : null,
            ]);

            $skipped = $pattern ? $this->makeSessions($group, $program, $candidates, $pattern, $data['default_room_id'] ?? null) : [];

            return ['group' => $group->fresh(), 'skipped' => $skipped];
        });
    }

    /**
     * What a pattern would create, day by day, without saving: whether the day is open for training and the room is free.
     *
     * @param  array<string, mixed>  $pattern  start_date, weekdays (0 = Sunday), starts, ends, count or end_date
     * @return list<array<string, mixed>>
     */
    public function preview(array $pattern, ?string $roomId = null): array
    {
        $candidates = $this->candidates($pattern);
        if (! $candidates) {
            return [];
        }
        $closed = $this->calendar->closedDates(CarbonImmutable::parse($candidates[0]['date']), CarbonImmutable::parse(end($candidates)['date']))->keyBy('date');

        return array_map(function (array $c) use ($closed, $roomId, $pattern) {
            $range = $this->range($c['date'], $pattern);
            $free = null;
            if ($roomId) {
                $free = $this->rooms->isFree($roomId, $range[0], $range[1]);
            }

            return [
                'date' => $c['date'], 'starts_at' => $range[0]->toIso8601String(), 'ends_at' => $range[1]->toIso8601String(),
                'allowed' => ! $closed->has($c['date']), 'reason' => $closed->get($c['date'])['kind'] ?? null, 'room_available' => $free,
            ];
        }, $candidates);
    }

    /**
     * A new group with the same settings and sessions moved to start on `start_date`.
     *
     * @return array{group: TrainingGroup, skipped: list<array<string, mixed>>}
     */
    public function clone(TrainingGroup $source, array $data, User $by): array
    {
        $shift = $source->start_date && ! empty($data['start_date']) ? (int) $source->start_date->diffInDays(CarbonImmutable::parse($data['start_date']), false) : 0;

        return DB::transaction(function () use ($source, $data, $shift) {
            $program = $source->program;
            $sequence = (int) $program->groups()->withTrashed()->max('sequence') + 1;
            $copy = TrainingGroup::create([
                'program_id' => $program->id, 'code' => $program->code.'-G'.$sequence, 'sequence' => $sequence, 'title_ar' => $source->title_ar, 'title_en' => $source->title_en,
                'delivery_mode' => $source->delivery_mode, 'start_date' => $source->start_date?->addDays($shift), 'end_date' => $source->end_date?->addDays($shift),
                'registration_opens_at' => $source->registration_opens_at?->addDays($shift), 'registration_closes_at' => $source->registration_closes_at?->addDays($shift),
                'capacity' => $data['capacity'] ?? $source->capacity, 'min_attendance_percent' => $source->min_attendance_percent, 'supervisor_id' => $source->supervisor_id,
                'default_room_id' => $source->default_room_id, 'status' => TrainingGroup::PLANNED, 'is_emergency' => $source->is_emergency, 'published_at' => null,
            ]);

            $skipped = [];
            foreach ($source->sessions()->where('status', '!=', 'cancelled')->orderBy('starts_at')->get() as $s) {
                $start = $s->starts_at->copy()->addDays($shift);
                $end = $s->ends_at->copy()->addDays($shift);
                if ($this->calendar->closedDates($start, $end)->isNotEmpty()) {
                    $skipped[] = ['date' => $start->toDateString(), 'reason' => 'closed_day'];

                    continue;
                }
                $roomId = $s->training_room_id && $this->rooms->isFree($s->training_room_id, $start, $end) ? $s->training_room_id : null;
                $copy->sessions()->create([
                    'program_id' => $program->id, 'title_ar' => $s->title_ar, 'title_en' => $s->title_en, 'description' => $s->description, 'sequence' => $s->sequence,
                    'starts_at' => $start, 'ends_at' => $end, 'training_room_id' => $roomId, 'mode' => $s->mode, 'online_platform' => $s->online_platform, 'status' => 'scheduled',
                ]);
            }

            return ['group' => $copy->fresh(), 'skipped' => $skipped];
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(TrainingGroup $group, array $data): TrainingGroup
    {
        if (($data['is_emergency'] ?? false) && ! $group->is_emergency && blank($data['status_reason'] ?? null) && blank($group->status_reason)) {
            throw new BusinessRuleException(__('messages.groups.emergency_reason'), 'emergency_reason_required');
        }
        $group->update($data);

        return $group->fresh();
    }

    public function publish(TrainingGroup $group): TrainingGroup
    {
        $group->update(['published_at' => $group->published_at ?? now()]);

        return $group;
    }

    public function unpublish(TrainingGroup $group): TrainingGroup
    {
        if ($group->registrations()->whereIn('status', [...Registration::SEAT_HOLDING, Registration::STATUS_WAITLISTED])->exists()) {
            throw new BusinessRuleException(__('messages.groups.has_registrations'), 'has_registrations');
        }
        $group->update(['published_at' => null]);

        return $group;
    }

    public function delete(TrainingGroup $group): void
    {
        if ($group->registrations()->exists()) {
            throw new BusinessRuleException(__('messages.groups.has_registrations'), 'has_registrations');
        }
        DB::transaction(function () use ($group) {
            $group->sessions()->delete();
            $group->delete();
        });
    }

    /** The status machine: only allowed changes, a reason where required, and everyone affected is told. */
    public function transition(TrainingGroup $group, string $to, ?string $reason, ?string $postponedTo, User $by): TrainingGroup
    {
        $from = $group->status;
        if (! in_array($to, TrainingGroup::TRANSITIONS[$from] ?? [], true)) {
            throw new BusinessRuleException(__('messages.groups.invalid_transition', ['from' => $from, 'to' => $to]), 'invalid_transition');
        }
        if (in_array($to, TrainingGroup::REASON_REQUIRED, true) && blank($reason)) {
            throw new BusinessRuleException(__('messages.groups.reason_required'), 'reason_required');
        }

        DB::transaction(function () use ($group, $to, $reason, $postponedTo, $from, $by) {
            $group->update([
                'status' => $to, 'status_reason' => in_array($to, TrainingGroup::REASON_REQUIRED, true) ? $reason : null,
                'postponed_to' => $to === TrainingGroup::POSTPONED ? $postponedTo : null,
                'published_at' => $to === TrainingGroup::REGISTRATION_OPEN ? ($group->published_at ?? now()) : $group->published_at,
            ]);
            if (in_array($to, [TrainingGroup::POSTPONED, TrainingGroup::CANCELLED], true)) {
                $this->releaseRooms($group, $to);
            }
            AuditLog::create([
                'user_id' => $by->id, 'action' => 'group_status_changed', 'auditable_type' => TrainingGroup::class, 'auditable_id' => $group->id,
                'old_values' => ['status' => $from], 'new_values' => ['status' => $to, 'reason' => $reason, 'postponed_to' => $postponedTo],
            ]);
        });

        $this->syncProgramStatus($group->program);
        $this->announce($group->fresh(), $from, $to);

        return $group->fresh();
    }

    /**
     * Moves the groups of programs that run several groups along with their dates: open for registration, ongoing,
     * completed — or incomplete when the end date has passed and no session was held. (Programs with a single group are
     * moved by the program lifecycle, which keeps their group in step.)
     *
     * @return array{opened: int, started: int, completed: int, incomplete: int}
     */
    public function advance(): array
    {
        $counts = ['opened' => 0, 'started' => 0, 'completed' => 0, 'incomplete' => 0];
        $multi = Program::withCount('groups')->get()->filter(fn (Program $p) => $p->groups_count > 1)->pluck('id');
        $system = User::whereHas('roles', fn ($q) => $q->where('slug', 'super_admin'))->first();
        $touched = collect();

        TrainingGroup::whereIn('program_id', $multi)->whereIn('status', [TrainingGroup::PLANNED, TrainingGroup::REGISTRATION_OPEN, TrainingGroup::ONGOING])->with('program')->get()->each(function (TrainingGroup $g) use (&$counts, $system, $touched) {
            $today = today();
            try {
                if (in_array($g->status, [TrainingGroup::PLANNED, TrainingGroup::REGISTRATION_OPEN], true) && $g->published_at && $g->start_date && $g->start_date->lte($today)) {
                    $this->move($g, TrainingGroup::ONGOING, null, $system);
                    $counts['started']++;
                } elseif ($g->status === TrainingGroup::PLANNED && $g->published_at && (! $g->registration_opens_at || $g->registration_opens_at->lte(now())) && (! $g->registration_closes_at || $g->registration_closes_at->gt(now()))) {
                    $this->move($g, TrainingGroup::REGISTRATION_OPEN, null, $system);
                    $counts['opened']++;
                }
                $g->refresh();
                if ($g->status === TrainingGroup::ONGOING && $g->end_date && $g->end_date->lt($today)) {
                    $held = $g->sessions()->where('status', '!=', 'cancelled')->whereHas('attendance')->exists();
                    $this->move($g, $held ? TrainingGroup::COMPLETED : TrainingGroup::INCOMPLETE, $held ? null : __('messages.groups.not_held'), $system);
                    $counts[$held ? 'completed' : 'incomplete']++;
                }
                $touched->push($g->program);
            } catch (Throwable $e) {
                report($e);
            }
        });
        $touched->unique('id')->each(fn (Program $p) => $this->syncProgramStatus($p));

        return $counts;
    }

    /** A program that runs several groups takes the status of its most advanced group. */
    public function syncProgramStatus(Program $program): void
    {
        $groups = $program->groups()->get();
        if ($groups->count() < 2) {
            return;
        }
        $status = match (true) {
            $groups->contains('status', TrainingGroup::ONGOING) => Program::STATUS_IN_PROGRESS,
            $groups->contains('status', TrainingGroup::REGISTRATION_OPEN) => Program::STATUS_REGISTRATION_OPEN,
            $groups->every(fn ($g) => in_array($g->status, [TrainingGroup::COMPLETED, TrainingGroup::CANCELLED, TrainingGroup::INCOMPLETE], true)) && $groups->contains('status', TrainingGroup::COMPLETED) => Program::STATUS_COMPLETED,
            default => null,
        };
        if ($status && $program->status !== $status && ! in_array($program->status, [Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED], true)) {
            $program->update(['status' => $status]);
        }
    }

    // -----------------------------------------------------------------------------------------------------------

    private function move(TrainingGroup $g, string $to, ?string $reason, ?User $by): void
    {
        $from = $g->status;
        $g->update(['status' => $to, 'status_reason' => $reason]);
        if ($by) {
            AuditLog::create(['user_id' => $by->id, 'action' => 'group_status_changed', 'auditable_type' => TrainingGroup::class, 'auditable_id' => $g->id, 'old_values' => ['status' => $from], 'new_values' => ['status' => $to, 'reason' => $reason, 'automatic' => true]]);
        }
    }

    /** Sessions of a postponed or cancelled group give their rooms back (a cancelled group's sessions are cancelled too). */
    private function releaseRooms(TrainingGroup $group, string $to): void
    {
        $group->sessions()->where('status', 'scheduled')->get()->each(fn (ProgramSession $s) => $s->update(['training_room_id' => null, 'status' => $to === TrainingGroup::CANCELLED ? 'cancelled' : $s->status]));
    }

    private function announce(TrainingGroup $group, string $from, string $to): void
    {
        if (! in_array($to, [TrainingGroup::POSTPONED, TrainingGroup::CANCELLED, TrainingGroup::INCOMPLETE, TrainingGroup::COMPLETED, TrainingGroup::REGISTRATION_OPEN, TrainingGroup::ONGOING], true)) {
            return;
        }
        $event = match ($to) {
            TrainingGroup::POSTPONED => 'group.postponed', TrainingGroup::CANCELLED => 'group.cancelled', default => 'group.status_changed',
        };
        // Only a delay or a cancellation reaches the people who are registered; the other changes are for the supervisor.
        $registrants = in_array($to, [TrainingGroup::POSTPONED, TrainingGroup::CANCELLED], true)
            ? $group->registrations()->whereIn('status', [Registration::STATUS_PENDING, Registration::STATUS_APPROVED, Registration::STATUS_WAITLISTED])->with('employee.supervisor')->get() : collect();
        $managers = $registrants->map(fn (Registration $r) => $r->employee?->supervisor?->user_id)->filter();
        $ids = $registrants->pluck('employee.user_id')->merge($managers)->push($group->supervisor_id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $program = $group->program;
        $when = $group->postponed_to?->toDateString();
        $labels = ['ar' => ['postponed' => 'تأجيل', 'cancelled' => 'إلغاء'], 'en' => ['postponed' => 'postponed', 'cancelled' => 'cancelled']];
        $ar = $labels['ar'][$to] ?? 'تحديث';
        $en = $labels['en'][$to] ?? 'updated';
        $this->notifications->broadcast(
            $ids, $event,
            ['ar' => "تم {$ar} مجموعة تدريبية", 'en' => "A training group was {$en}"],
            [
                'ar' => "مجموعة «{$group->displayTitle('ar')}» من برنامج «{$program->title_ar}»: {$ar}".($group->status_reason ? " — {$group->status_reason}" : '').($when ? " (إلى {$when})" : '').'.',
                'en' => "Group \"{$group->displayTitle('en')}\" of \"{$program->title_en}\": {$en}".($group->status_reason ? " — {$group->status_reason}" : '').($when ? " (to {$when})" : '').'.',
            ],
            ['group_id' => $group->id, 'program_id' => $program->id, 'reason' => $group->status_reason, 'route' => '/training'],
        );
    }

    /** @return list<array{date: string}> candidate days of a pattern, in order */
    private function candidates(array $pattern): array
    {
        $weekdays = array_map('intval', $pattern['weekdays'] ?? []);
        $count = isset($pattern['count']) ? min((int) $pattern['count'], 120) : null;
        $day = CarbonImmutable::parse($pattern['start_date']);
        $last = isset($pattern['end_date']) ? CarbonImmutable::parse($pattern['end_date']) : $day->addDays(365);
        $out = [];
        for ($i = 0; $i < 400 && $day->lte($last) && ($count === null || count($out) < $count); $i++, $day = $day->addDay()) {
            if (in_array($day->dayOfWeek, $weekdays, true)) {
                $out[] = ['date' => $day->toDateString()];
            }
        }

        return $out;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(string $date, array $pattern): array
    {
        $tz = config('app.timezone');

        return [CarbonImmutable::parse($date.' '.($pattern['starts'] ?? '08:00'), $tz), CarbonImmutable::parse($date.' '.($pattern['ends'] ?? '13:00'), $tz)];
    }

    /** @param  list<array{date: string}>  $candidates */
    private function makeSessions(TrainingGroup $group, Program $program, array $candidates, array $pattern, ?string $roomId): array
    {
        if (! $candidates) {
            return [];
        }
        $closed = $this->calendar->closedDates(CarbonImmutable::parse($candidates[0]['date']), CarbonImmutable::parse(end($candidates)['date']))->keyBy('date');
        $skipped = [];
        $n = 0;
        foreach ($candidates as $c) {
            [$start, $end] = $this->range($c['date'], $pattern);
            if ($closed->has($c['date'])) {
                $skipped[] = ['date' => $c['date'], 'reason' => 'closed_day', 'kind' => $closed->get($c['date'])['kind'] ?? null];

                continue;
            }
            try {
                $this->day->assertWithinDay($start, $end);
            } catch (BusinessRuleException) {
                $skipped[] = ['date' => $c['date'], 'reason' => 'outside_training_day'];

                continue;
            }
            $n++;
            $room = $roomId && $this->rooms->isFree($roomId, $start, $end) ? $roomId : null;
            if ($roomId && ! $room) {
                $skipped[] = ['date' => $c['date'], 'reason' => 'room_busy', 'assigned' => true];
            }
            $group->sessions()->create([
                'program_id' => $program->id, 'title_ar' => "{$program->title_ar} — اليوم {$n}", 'title_en' => "{$program->title_en} — Day {$n}", 'sequence' => $n,
                'starts_at' => $start, 'ends_at' => $end, 'training_room_id' => $room, 'status' => 'scheduled',
                'mode' => $group->delivery_mode === 'online' ? 'online' : 'in_person',
            ]);
        }

        return $skipped;
    }
}
