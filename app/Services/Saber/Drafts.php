<?php

namespace App\Services\Saber;

use App\Exceptions\BusinessRuleException;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\RoomBooking;
use App\Models\SaberRevision;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\NotificationService;
use App\Services\Programs\ProgramInstruments;
use App\Services\RoomService;
use App\Services\TrainerService;
use App\Services\TrainingDaySettings;
use App\Services\TrainingGroupService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * سابر+ as a planning workspace (docs/saber/05-review-and-flexible-import.md). A programme's planned state — its groups (number, name,
 * colour, term, supervisor) and each group's days (date, times, room, trainer) — lives in one open draft revision; SABER+ edits change the
 * draft only, checked against the training calendar, the training day, the live bookings of other programmes and the other open drafts. A
 * reviewer publishes the revision: everything is applied to the live programme in one transaction through the same rules, or nothing is.
 */
class Drafts
{
    public function __construct(
        private readonly Workbench $bench,
        private readonly CalendarService $calendar,
        private readonly RoomService $rooms,
        private readonly TrainerService $trainers,
        private readonly TrainingDaySettings $day,
        private readonly TrainingGroupService $groups,
        private readonly NotificationService $notifications,
    ) {}

    // ── reading ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function open(Program $program): ?SaberRevision
    {
        return SaberRevision::where('program_id', $program->id)->whereIn('status', SaberRevision::OPEN)->latest()->first();
    }

    /** The open draft, or a new one starting from the live programme. */
    public function draft(Program $program, User $actor, string $source = 'manual'): SaberRevision
    {
        return $this->open($program) ?? SaberRevision::create([
            // Only the programme fields SABER+ changes are kept (so a later edit elsewhere is not overwritten); the groups are the full plan.
            'program_id' => $program->id, 'status' => SaberRevision::DRAFT, 'source' => $source, 'state' => ['program' => [], 'groups' => $this->live($program)['groups']], 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    /** The live programme in the draft's shape. @return array{program: array<string, mixed>, groups: list<array<string, mixed>>} */
    public function live(Program $program): array
    {
        $count = $this->bench->daysPerGroup($program);

        return [
            'program' => ['training_days' => $program->training_days, 'coordinator_id' => $program->coordinator_id, 'total_hours' => (float) $program->total_hours, 'delivery_mode' => $program->delivery_mode,
                'start_date' => $program->start_date?->toDateString(), 'objectives' => $program->objectives, 'kit_ready' => $program->kit_ready],
            'groups' => $program->groups()->orderBy('sequence')->get()->map(fn (TrainingGroup $g) => [
                'key' => $g->id, 'live' => true, 'deleted' => false, 'number' => $g->sequence, 'title' => $g->title_ar, 'color' => $g->color, 'term' => $g->term,
                'supervisor_id' => $g->supervisor_id, 'default_room_id' => $g->default_room_id, 'status' => $g->status,
                'days' => collect($this->bench->days($g, $count))->filter(fn ($d) => $d['date'])->map(fn ($d) => $this->dayOf($d))->values()->all(),
            ])->values()->all(),
        ];
    }

    /** Days per group in a state (the draft's own number of days, else the programme's). */
    /** A programme field as planned: the draft's value when it changed it, else the live one. */
    public function field(Program $program, array $state, string $field): mixed
    {
        return array_key_exists($field, $state['program'] ?? []) ? $state['program'][$field] : $program->{$field};
    }

    public function daysPerGroup(Program $program, array $state): int
    {
        $p = clone $program;
        $p->training_days = $state['program']['training_days'] ?? $program->training_days;
        $p->total_hours = $state['program']['total_hours'] ?? $program->total_hours;

        return $this->bench->daysPerGroup($p);
    }

    /**
     * A state's group as the workbench shows it: days 1…n (empty ones included) with the live session and attendance lock of each.
     *
     * @return list<array<string, mixed>>
     */
    public function daysOf(Program $program, array $group, int $count): array
    {
        $live = ! empty($group['live']) ? collect($this->bench->days(TrainingGroup::find($group['key']) ?? new TrainingGroup, $count))->keyBy('idx') : collect();
        $byIdx = collect($group['days'] ?? [])->keyBy('idx');
        $last = max($count, (int) ($byIdx->keys()->max() ?? 0), (int) ($live->filter(fn ($d) => $d['date'])->keys()->max() ?? 0));
        $out = [];
        for ($i = 1; $i <= $last; $i++) {
            $d = $byIdx->get($i);
            $l = $live->get($i);
            $out[] = [
                'idx' => $i, 'extra' => $i > $count, 'session_id' => $l['session_id'] ?? null, 'date' => $d['date'] ?? null, 'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null,
                'room_id' => $d['room_id'] ?? null, 'trainer_id' => $d['trainer_id'] ?? null, 'status' => $l['status'] ?? null, 'locked' => (bool) ($l['locked'] ?? false),
                'changed' => $this->dayOf($l ?? []) != $this->dayOf($d ?? []),
            ];
        }

        return $out;
    }

    /**
     * Who holds each room and trainer in a slot, as seen from day `idx` of a group of this programme: live sessions of other programmes,
     * bookings, the other days of this programme's plan and the other open drafts.
     *
     * @return array{rooms: array<string, string>, trainers: array<string, string>}
     */
    public function availability(Program $program, string $key, int $idx, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rev = $this->open($program) ?? new SaberRevision(['program_id' => $program->id, 'state' => $this->live($program)]);
        $state = $rev->state;
        $rooms = [];
        foreach (TrainingRoom::where('status', '!=', 'inactive')->pluck('id') as $id) {
            if ($holder = $this->roomHolder($rev, $state, $id, $start, $end, $key, $idx)) {
                $rooms[$id] = $holder;
            }
        }
        $trainers = ProgramSession::with('program:id,title_ar,title_en')->whereNotNull('trainer_id')->where('status', '!=', 'cancelled')->where('program_id', '!=', $program->id)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get()->mapWithKeys(fn ($s) => [$s->trainer_id => $s->program?->title_ar ?: $s->program?->title_en])->all();
        $scan = function (array $groups, string $label, bool $self) use (&$trainers, $start, $end, $key, $idx) {
            foreach ($groups as $g) {
                foreach (empty($g['deleted']) ? $g['days'] ?? [] : [] as $d) {
                    if (empty($d['trainer_id']) || empty($d['date']) || isset($trainers[$d['trainer_id']]) || ($self && $g['key'] === $key && (int) $d['idx'] === $idx)) {
                        continue;
                    }
                    [$s, $e] = $this->span($d['date'], $d['start_time'] ?? null, $d['end_time'] ?? null);
                    if ($s < $end && $e > $start) {
                        $trainers[$d['trainer_id']] = $label;
                    }
                }
            }
        };
        $scan($state['groups'], __('messages.saber.review.this_draft'), true);
        foreach (SaberRevision::with('program:id,title_ar,title_en')->whereIn('status', SaberRevision::OPEN)->where('program_id', '!=', $program->id)->get() as $other) {
            $scan($other->state['groups'] ?? [], __('messages.saber.review.other_draft', ['title' => $other->program?->title_ar ?: $other->program?->title_en]), false);
        }

        return ['rooms' => $rooms, 'trainers' => $trainers];
    }

    // ── editing the draft ────────────────────────────────────────────────────────────────────────────────────────

    /** @param array{training_days?: ?int, coordinator_id?: ?string, total_hours?: ?float, delivery_mode?: ?string, start_date?: ?string, objectives?: ?array} $patch */
    public function setProgram(SaberRevision $rev, array $patch, User $actor): SaberRevision
    {
        $state = $rev->state;
        $state['program'] = array_merge($state['program'] ?? [], $patch);

        return $this->save($rev, $state, $actor);
    }

    /** A new group in the draft (a real id is given now; the group exists live only once published). */
    public function addGroup(SaberRevision $rev, User $actor, array $attrs = []): array
    {
        $state = $rev->state;
        $numbers = collect($state['groups'])->pluck('number');
        $used = collect($state['groups'])->reject(fn ($g) => $g['deleted'] ?? false)->pluck('color')->filter()->map(fn ($c) => strtoupper($c))->all();
        $group = [
            'key' => (string) Str::uuid(), 'live' => false, 'deleted' => false, 'number' => (int) ($numbers->max() ?? 0) + 1, 'title' => $attrs['title'] ?? null,
            'color' => collect(Workbench::PALETTE)->first(fn ($c) => ! in_array(strtoupper($c), $used, true)) ?? Workbench::PALETTE[0], 'term' => null,
            'supervisor_id' => $attrs['supervisor_id'] ?? null, 'default_room_id' => null, 'status' => TrainingGroup::PLANNED, 'days' => [],
        ];
        $group['title'] ??= TrainingGroup::defaultTitle($group['number'], 'ar');
        $state['groups'][] = $group;
        $this->save($rev, $state, $actor);

        return $group;
    }

    /** @param array{title?: ?string, number?: int, color?: ?string, term?: ?string, supervisor_id?: ?string} $patch */
    public function updateGroup(SaberRevision $rev, string $key, array $patch, User $actor): array
    {
        $state = $rev->state;
        $i = $this->index($state, $key);
        $others = collect($state['groups'])->reject(fn ($g, $k) => $k === $i || ($g['deleted'] ?? false));
        if (array_key_exists('title', $patch)) {
            $title = trim((string) $patch['title']);
            if ($title !== '' && $others->contains(fn ($g) => trim((string) $g['title']) === $title)) {
                throw new BusinessRuleException(__('messages.groups.duplicate_title', ['title' => $title]), 'duplicate_title');
            }
            $patch['title'] = $title !== '' ? $title : TrainingGroup::defaultTitle((int) $state['groups'][$i]['number'], 'ar');
        }
        if (array_key_exists('number', $patch) && $others->contains('number', (int) $patch['number'])) {
            throw new BusinessRuleException(__('messages.saber.number_taken', ['n' => $patch['number']]), 'number_taken');
        }
        $state['groups'][$i] = array_merge($state['groups'][$i], $patch);
        $this->save($rev, $state, $actor);

        return $state['groups'][$i];
    }

    public function deleteGroup(SaberRevision $rev, string $key, User $actor): void
    {
        $state = $rev->state;
        $i = $this->index($state, $key);
        if (collect($state['groups'])->reject(fn ($g) => $g['deleted'] ?? false)->count() <= 1) {
            throw new BusinessRuleException(__('messages.groups.last_group'), 'last_group');
        }
        if (! empty($state['groups'][$i]['live'])) {
            if (Registration::where('training_group_id', $key)->exists()) {
                throw new BusinessRuleException(__('messages.groups.has_registrations'), 'has_registrations');
            }
            $state['groups'][$i]['deleted'] = true;
        } else {
            array_splice($state['groups'], $i, 1);
        }
        $this->save($rev, $state, $actor);
    }

    /**
     * Sets the date, times, room or trainer of day `idx` in the draft — the workbench rules, against live bookings of other programmes and
     * the other drafts: the trainer of day 1 and the first room spread where free; an explicit clash is refused; an inherited one is cleared.
     *
     * @param  array{date?: ?string, start_time?: ?string, end_time?: ?string, room_id?: ?string, trainer_id?: ?string}  $patch
     * @return list<array{type: string, text: string}>
     */
    public function setDay(SaberRevision $rev, string $key, int $idx, array $patch, User $actor): array
    {
        $program = $rev->program;
        $state = $rev->state;
        $gi = $this->index($state, $key);
        $group = $state['groups'][$gi];
        $this->assertEditable($program, $group, $idx);
        if (array_key_exists('date', $patch) && blank($patch['date'])) {
            $state['groups'][$gi]['days'] = array_values(array_filter($group['days'], fn ($d) => (int) $d['idx'] !== $idx));
            $this->save($rev, $state, $actor);

            return [['type' => 'info', 'text' => __('messages.saber.day_reset', ['n' => $idx])]];
        }
        $days = collect($group['days'])->keyBy('idx');
        $current = $days->get($idx);
        $settings = $this->day->all();
        $date = $patch['date'] ?? ($current['date'] ?? null);
        if (! $date) {
            throw new BusinessRuleException(__('messages.saber.date_first'), 'date_first');
        }
        $startTime = $patch['start_time'] ?? ($current['start_time'] ?? $settings['day_start']);
        $endTime = $patch['end_time'] ?? ($current['end_time'] ?? $settings['day_end']);
        [$start, $end] = $this->span($date, $startTime, $endTime);
        if ($end->lessThanOrEqualTo($start)) {
            throw new BusinessRuleException(__('messages.saber.end_after_start'), 'end_before_start');
        }
        $moved = ! $current || $current['date'] !== $date || $current['start_time'] !== $startTime || $current['end_time'] !== $endTime;
        $online = $program->delivery_mode === 'online';
        $roomId = array_key_exists('room_id', $patch) ? $patch['room_id'] : ($current['room_id'] ?? $group['default_room_id'] ?? null);
        $trainerId = array_key_exists('trainer_id', $patch) ? $patch['trainer_id'] : ($current['trainer_id'] ?? collect($group['days'])->sortBy('idx')->pluck('trainer_id')->filter()->first());
        $notices = [];
        if ($moved) {
            $this->calendar->assertTrainingAllowed($start, $end);
            $this->day->assertWithinDay($start, $end);
        }
        if ($roomId && ! $online && ($moved || $roomId !== ($current['room_id'] ?? null))) {
            $holder = $this->roomHolder($rev, $state, $roomId, $start, $end, $key, $idx);
            if ($holder) {
                if (array_key_exists('room_id', $patch)) {
                    throw new BusinessRuleException(__('messages.saber.room_taken', ['room' => $this->roomName($roomId), 'what' => $holder]), 'room_conflict');
                }
                $notices[] = ['type' => 'warning', 'text' => __('messages.saber.room_cleared', ['room' => $this->roomName($roomId), 'date' => $date])];
                $roomId = null;
            }
        }
        if ($trainerId && ($moved || $trainerId !== ($current['trainer_id'] ?? null))) {
            $holder = $this->trainerHolder($rev, $state, $trainerId, $start, $end, $key, $idx);
            if ($holder) {
                if (array_key_exists('trainer_id', $patch)) {
                    throw new BusinessRuleException(__('messages.saber.trainer_busy', ['what' => $holder]), 'trainer_conflict');
                }
                $notices[] = ['type' => 'warning', 'text' => __('messages.saber.trainer_cleared', ['date' => $date])];
                $trainerId = null;
            }
        }
        $hadRoom = collect($group['days'])->contains(fn ($d) => ! empty($d['room_id'])) || ! empty($group['default_room_id']);
        $days->put($idx, ['idx' => $idx, 'date' => $date, 'start_time' => $startTime, 'end_time' => $endTime, 'room_id' => $online ? null : $roomId, 'trainer_id' => $trainerId]);
        $state['groups'][$gi]['days'] = $days->sortKeys()->values()->all();

        if ($idx === 1 && ! empty($patch['trainer_id'])) {
            $notices = [...$notices, ...$this->spread($rev, $state, $gi, $idx, 'trainer_id', $patch['trainer_id'])];
        }
        if (! empty($patch['room_id']) && ! $hadRoom && ! $online) {
            $state['groups'][$gi]['default_room_id'] = $patch['room_id'];
            $notices = [...$notices, ...$this->spread($rev, $state, $gi, $idx, 'room_id', $patch['room_id'])];
        }
        $this->save($rev, $state, $actor);

        return $notices;
    }

    public function resetGroup(SaberRevision $rev, string $key, User $actor): int
    {
        $state = $rev->state;
        $gi = $this->index($state, $key);
        $locked = collect($this->daysOf($rev->program, $state['groups'][$gi], 0))->filter(fn ($d) => $d['locked'])->pluck('idx')->all();
        $before = count($state['groups'][$gi]['days']);
        $state['groups'][$gi]['days'] = array_values(array_filter($state['groups'][$gi]['days'], fn ($d) => in_array((int) $d['idx'], $locked, true)));
        $state['groups'][$gi]['default_room_id'] = null;
        $this->save($rev, $state, $actor);

        return $before - count($state['groups'][$gi]['days']);
    }

    /**
     * Replaces a group's days with dates (day 1 = earliest), each with its room, trainer and times when given and free: a closed date is
     * skipped, a taken room or trainer is left empty — every such case is reported. Used by the plan of the coming days and the imports.
     *
     * @param  list<array{date: string, room_id?: ?string, trainer_id?: ?string, start_time?: ?string, end_time?: ?string}>  $days
     * @return array{notices: list<array{type: string, text: string}>, failures: list<array{date: string, message: string}>}
     */
    public function replaceDays(SaberRevision $rev, string $key, array $days, User $actor): array
    {
        $state = $rev->state;
        $gi = $this->index($state, $key);
        $group = $state['groups'][$gi];
        if (collect($this->daysOf($rev->program, $group, 0))->contains('locked', true)) {
            throw new BusinessRuleException(__('messages.saber.plan.has_attendance'), 'day_has_attendance');
        }
        $state['groups'][$gi]['days'] = [];
        $state['groups'][$gi]['default_room_id'] = null;
        $rev->state = $state;
        usort($days, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $notices = [];
        $failures = [];
        $idx = 0;
        foreach ($days as $d) {
            $patch = array_filter(['date' => $d['date'], 'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null, 'room_id' => $d['room_id'] ?? null, 'trainer_id' => $d['trainer_id'] ?? null]);
            try {
                $notices = [...$notices, ...$this->setDay($rev, $key, $idx + 1, $patch, $actor)];
                $idx++;
            } catch (BusinessRuleException $e) {
                if (in_array($e->errorCode, ['calendar_closed', 'outside_training_day', 'end_before_start'], true)) {
                    $failures[] = ['date' => $d['date'], 'message' => $e->getMessage()];

                    continue;
                }
                $failures[] = ['date' => $d['date'], 'message' => $e->getMessage()];
                $this->setDay($rev, $key, $idx + 1, array_diff_key($patch, ['room_id' => 1, 'trainer_id' => 1]), $actor);
                $idx++;
            }
        }

        return ['notices' => array_values(collect($notices)->unique('text')->all()), 'failures' => $failures];
    }

    // ── review ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function submit(SaberRevision $rev, User $actor): SaberRevision
    {
        $rev->update(['status' => SaberRevision::SUBMITTED, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'review_note' => null]);
        $reviewers = User::where('status', 'active')->where('id', '!=', $actor->id)
            ->whereHas('roles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN)->orWhereHas('permissions', fn ($p) => $p->where('slug', 'saber.publish')))->pluck('id');
        $title = $rev->program->title_ar;
        if ($reviewers->isNotEmpty()) {
            $this->notifications->broadcast($reviewers, 'saber.revision_submitted', ['ar' => 'تعديلات سابر+ بانتظار المراجعة', 'en' => 'SABER+ changes waiting for review'],
                ['ar' => "{$actor->displayName('ar')} أرسل تعديلات «{$title}» للمراجعة.", 'en' => "{$actor->displayName('en')} sent the changes to “{$rev->program->title_en}” for review."],
                ['revision_id' => $rev->id, 'route' => '/admin/programs/saber-review']);
        }

        return $rev->fresh();
    }

    /** Back to the planner with the reviewer's note; the draft stays as it is. */
    public function returnToDraft(SaberRevision $rev, ?string $note, User $actor): SaberRevision
    {
        $rev->update(['status' => SaberRevision::DRAFT, 'review_note' => $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $this->tellAuthor($rev, 'saber.revision_returned', ['ar' => 'أُعيدت تعديلات سابر+ للمراجعة', 'en' => 'SABER+ changes returned'], $note);

        return $rev->fresh();
    }

    public function discard(SaberRevision $rev, ?string $note, User $actor): void
    {
        $rev->update(['status' => SaberRevision::DISCARDED, 'review_note' => $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        if ($actor->id !== $rev->created_by) {
            $this->tellAuthor($rev, 'saber.revision_discarded', ['ar' => 'لم تُعتمد تعديلات سابر+', 'en' => 'SABER+ changes discarded'], $note);
        }
    }

    /**
     * Applies the revision to the live programme: programme fields, groups (removed, created, renamed, renumbered, supervisor) and every day
     * that differs — each through the calendar, training-day, room and trainer rules. One failure and nothing is applied; the failures are
     * returned so the planner can fix them. A draft programme is published with it.
     *
     * @return array{changed_groups: int, changed_days: int}
     */
    public function publish(SaberRevision $rev, ?string $note, User $reviewer): array
    {
        if ($rev->status !== SaberRevision::SUBMITTED && $rev->status !== SaberRevision::DRAFT) {
            throw new BusinessRuleException(__('messages.saber.review.not_open'), 'not_open');
        }
        $program = $rev->program;
        $state = $rev->state;
        $failures = [];
        $changedGroups = [];
        $changedDays = 0;

        DB::beginTransaction();
        try {
            $patch = array_intersect_key($state['program'] ?? [], array_flip(['training_days', 'coordinator_id', 'total_hours', 'delivery_mode', 'start_date', 'objectives', 'kit_ready']));
            $program->update(array_filter($patch, fn ($v, $k) => $v !== null || in_array($k, ['coordinator_id', 'training_days'], true), ARRAY_FILTER_USE_BOTH));
            $program->refresh();
            $count = $this->daysPerGroup($program, $state);
            $online = $program->delivery_mode === 'online';

            // Groups: removed, then renumbered out of the way, then created / updated.
            foreach ($state['groups'] as $g) {
                if (! empty($g['live']) && ! empty($g['deleted']) && ($live = TrainingGroup::find($g['key']))) {
                    try {
                        $this->groups->delete($live);
                        $changedGroups[$g['key']] = true;
                    } catch (BusinessRuleException $e) {
                        $failures[] = ['group' => $g['title'], 'date' => null, 'message' => $e->getMessage()];
                    }
                }
            }
            TrainingGroup::where('program_id', $program->id)->update(['sequence' => DB::raw('sequence + 1000')]);
            $ids = [];
            foreach ($state['groups'] as $g) {
                if (! empty($g['deleted'])) {
                    continue;
                }
                $group = ! empty($g['live']) ? TrainingGroup::find($g['key']) : null;
                if (! $group) {
                    $group = $this->groups->create($program, ['title_ar' => null, 'delivery_mode' => TrainingGroup::modeFor($program->delivery_mode ?? 'in_person')], $reviewer)['group'];
                    $code = $program->code.'-G'.$g['number'];
                    if (! TrainingGroup::withTrashed()->where('code', $code)->exists()) {
                        $group->update(['code' => $code]);
                    }
                    $changedGroups[$g['key']] = true;
                }
                $before = $group->only(['title_ar', 'color', 'term', 'supervisor_id']);
                $group->update(['sequence' => (int) $g['number'], 'title_ar' => $g['title'] ?: TrainingGroup::defaultTitle((int) $g['number'], 'ar'), 'color' => $g['color'], 'term' => $g['term'],
                    'supervisor_id' => $g['supervisor_id'] ?? $group->supervisor_id, 'default_room_id' => $online ? null : ($g['default_room_id'] ?? null)]);
                if ($before != $group->only(['title_ar', 'color', 'term', 'supervisor_id'])) {
                    $changedGroups[$g['key']] = true;
                }
                $ids[$g['key']] = $group->id;
            }
            // Groups the draft does not know (added live meanwhile) keep a number after the draft's ones.
            $next = (int) collect($state['groups'])->max('number');
            TrainingGroup::where('program_id', $program->id)->where('sequence', '>', 999)->orderBy('sequence')->get()->each(function ($g) use (&$next) {
                $g->update(['sequence' => ++$next]);
            });

            // Days: first free every session that changes (rooms and trainers swapped inside the programme do not clash with themselves), then apply.
            $plan = [];
            foreach ($state['groups'] as $g) {
                if (! empty($g['deleted']) || ! isset($ids[$g['key']])) {
                    continue;
                }
                $group = TrainingGroup::find($ids[$g['key']]);
                $live = collect($this->bench->days($group, 0))->keyBy('idx');
                $want = collect($g['days'])->keyBy('idx');
                foreach ($live->keys()->merge($want->keys())->unique()->sort() as $idx) {
                    $l = $live->get($idx);
                    $w = $want->get($idx);
                    if ($this->dayOf($l ?? []) == $this->dayOf($w ?? [])) {
                        continue;
                    }
                    if (! empty($l['locked'])) {
                        $failures[] = ['group' => $g['title'], 'date' => $l['date'], 'message' => __('messages.saber.day_has_attendance', ['n' => $idx])];

                        continue;
                    }
                    $plan[] = ['group' => $group, 'title' => $g['title'], 'idx' => (int) $idx, 'session' => ! empty($l['session_id']) ? ProgramSession::find($l['session_id']) : null, 'want' => $w];
                }
            }
            foreach ($plan as $p) {
                $p['session']?->update(['training_room_id' => null, 'trainer_id' => null]);
            }
            foreach ($plan as $p) {
                try {
                    $this->applyDay($program, $p['group'], $p['idx'], $p['session'], $p['want'], $online);
                    $changedDays++;
                    $changedGroups[$p['group']->id] = true;
                } catch (BusinessRuleException $e) {
                    $failures[] = ['group' => $p['title'], 'date' => $p['want']['date'] ?? null, 'message' => $e->getMessage()];
                }
            }
            if ($failures !== []) {
                throw new BusinessRuleException(__('messages.saber.review.failed', ['n' => count($failures)]), 'publish_failed', ['failures' => $failures]);
            }

            $wasDraft = $program->status === Program::STATUS_DRAFT;
            if ($wasDraft) {
                $program->update(['status' => Program::STATUS_PUBLISHED]);
                TrainingGroup::where('program_id', $program->id)->whereNull('published_at')->update(['published_at' => now()]);
                // A new programme gets its trainee survey and trainer self-reflection, with their links (FormLinks), from the start.
                app(ProgramInstruments::class)->ensureForms($program->refresh(), $reviewer);
            }
            $rev->update(['status' => SaberRevision::PUBLISHED, 'published_state' => $state, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->tellAuthor($rev, 'saber.revision_published', ['ar' => 'نُشرت تعديلات سابر+', 'en' => 'SABER+ changes published'], $note);
        if (! $wasDraft) {
            $this->tellRegistrants($program, array_keys($changedGroups));
        }

        return ['changed_groups' => count($changedGroups), 'changed_days' => $changedDays];
    }

    /**
     * Old versus new, for the reviewer: programme fields, groups added / removed / changed and every day that differs.
     *
     * @return array<string, mixed>
     */
    public function diff(SaberRevision $rev): array
    {
        $program = $rev->program;
        $live = $this->live($program);
        $state = $rev->state;
        $rooms = TrainingRoom::pluck('name_ar', 'id');
        $trainers = Trainer::pluck('name_ar', 'id');
        $users = User::whereIn('id', collect($state['groups'])->pluck('supervisor_id')->merge(collect($live['groups'])->pluck('supervisor_id'))->push($state['program']['coordinator_id'] ?? null, $live['program']['coordinator_id'] ?? null)->filter()->unique())
            ->get(['id', 'name', 'name_ar'])->mapWithKeys(fn ($u) => [$u->id => $u->name_ar ?: $u->name]);
        $describe = fn (?array $d) => $d && ! empty($d['date']) ? ['date' => $d['date'], 'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null,
            'room' => ! empty($d['room_id']) ? ($rooms[$d['room_id']] ?? null) : null, 'trainer' => ! empty($d['trainer_id']) ? ($trainers[$d['trainer_id']] ?? null) : null] : null;

        $fields = [];
        foreach (['training_days', 'coordinator_id', 'total_hours', 'delivery_mode', 'start_date', 'kit_ready'] as $f) {
            $old = $live['program'][$f] ?? null;
            $new = array_key_exists($f, $state['program'] ?? []) ? $state['program'][$f] : $old;
            if ($old != $new) {
                $fields[] = ['field' => $f, 'old' => $f === 'coordinator_id' ? ($users[$old] ?? null) : $old, 'new' => $f === 'coordinator_id' ? ($users[$new] ?? null) : $new];
            }
        }
        $liveGroups = collect($live['groups'])->keyBy('key');
        $groups = [];
        foreach ($state['groups'] as $g) {
            $l = $liveGroups->get($g['key']);
            $kind = ! empty($g['deleted']) ? 'removed' : (! $l ? 'added' : 'changed');
            $meta = [];
            foreach (['number' => 'number', 'title' => 'title', 'term' => 'term', 'supervisor_id' => 'supervisor'] as $f => $label) {
                if ($l && ($l[$f] ?? null) != ($g[$f] ?? null) && $kind === 'changed') {
                    $meta[] = ['field' => $label, 'old' => $f === 'supervisor_id' ? ($users[$l[$f]] ?? null) : $l[$f], 'new' => $f === 'supervisor_id' ? ($users[$g[$f]] ?? null) : $g[$f]];
                }
            }
            $oldDays = collect($l['days'] ?? [])->keyBy('idx');
            $newDays = collect($kind === 'removed' ? [] : $g['days'])->keyBy('idx');
            $days = [];
            foreach ($oldDays->keys()->merge($newDays->keys())->unique()->sort() as $idx) {
                $o = $describe($oldDays->get($idx));
                $n = $describe($newDays->get($idx));
                if ($o != $n) {
                    $days[] = ['idx' => (int) $idx, 'old' => $o, 'new' => $n];
                }
            }
            if ($kind !== 'changed' || $meta || $days) {
                $groups[] = ['key' => $g['key'], 'title' => $g['title'], 'number' => $g['number'], 'color' => $g['color'], 'kind' => $kind, 'meta' => $meta, 'days' => $days];
            }
        }

        return ['program' => $fields, 'groups' => $groups, 'counts' => [
            'groups' => count($groups), 'days' => collect($groups)->sum(fn ($g) => count($g['days'])),
            'added' => collect($groups)->where('kind', 'added')->count(), 'removed' => collect($groups)->where('kind', 'removed')->count(),
        ], 'new_program' => $program->status === Program::STATUS_DRAFT];
    }

    /** @return array{groups: int, days: int} */
    public function counts(SaberRevision $rev): array
    {
        $diff = $this->diff($rev);

        return ['groups' => $diff['counts']['groups'], 'days' => $diff['counts']['days'], 'fields' => count($diff['program'])];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function applyDay(Program $program, TrainingGroup $group, int $idx, ?ProgramSession $session, ?array $want, bool $online): void
    {
        if (! $want || empty($want['date'])) {
            $session?->delete();

            return;
        }
        [$start, $end] = $this->span($want['date'], $want['start_time'] ?? $this->day->all()['day_start'], $want['end_time'] ?? $this->day->all()['day_end']);
        $moved = ! $session || ! $session->starts_at->equalTo($start) || ! $session->ends_at->equalTo($end);
        if ($moved) {
            $this->calendar->assertTrainingAllowed($start, $end);
            $this->day->assertWithinDay($start, $end);
        }
        $room = $online ? null : ($want['room_id'] ?? null);
        if ($room) {
            $this->rooms->assertBookable($room, $start, $end, $session?->id);
        }
        if (! empty($want['trainer_id'])) {
            $this->trainers->assertAssignable($want['trainer_id'], $start, $end, $session?->id);
        }
        $values = ['starts_at' => $start, 'ends_at' => $end, 'training_room_id' => $room, 'trainer_id' => $want['trainer_id'] ?? null];
        if ($session) {
            $session->update($values + ['sequence' => $idx]);
        } else {
            $program->sessions()->create($values + ['training_group_id' => $group->id, 'sequence' => $idx, 'mode' => $online ? 'online' : 'in_person', 'title_ar' => "اليوم {$idx}", 'title_en' => "Day {$idx}"]);
        }
    }

    /** The holder of a room in [start, end): a live session of another programme, a booking, a day of this draft, or a day of another draft. */
    private function roomHolder(SaberRevision $rev, array $state, string $roomId, CarbonImmutable $start, CarbonImmutable $end, string $key, int $idx): ?string
    {
        [$from, $to] = $this->roomSpan($start, $end);
        $session = ProgramSession::with('program:id,title_ar,title_en')->where('training_room_id', $roomId)->where('status', '!=', 'cancelled')
            ->where('program_id', '!=', $rev->program_id)->where('starts_at', '<', $to)->where('ends_at', '>', $from)->first();
        if ($session) {
            return $session->program?->title_ar ?: $session->program?->title_en;
        }
        $booking = RoomBooking::where('room_id', $roomId)->where('status', 'confirmed')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->first();
        if ($booking) {
            return $booking->title;
        }

        return $this->draftHolder($rev, $state, 'room_id', $roomId, $from, $to, $key, $idx);
    }

    private function trainerHolder(SaberRevision $rev, array $state, string $trainerId, CarbonImmutable $start, CarbonImmutable $end, string $key, int $idx): ?string
    {
        $session = ProgramSession::with('program:id,title_ar,title_en')->where('trainer_id', $trainerId)->where('status', '!=', 'cancelled')
            ->where('program_id', '!=', $rev->program_id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->first();
        if ($session) {
            return $session->program?->title_ar ?: $session->program?->title_en;
        }

        return $this->draftHolder($rev, $state, 'trainer_id', $trainerId, $start, $end, $key, $idx);
    }

    /** A day in this draft (another day or group) or in another open draft holding the same room or trainer. */
    private function draftHolder(SaberRevision $rev, array $state, string $field, string $value, CarbonImmutable $from, CarbonImmutable $to, string $key, int $idx): ?string
    {
        $check = function (array $groups, string $label, bool $self) use ($field, $value, $from, $to, $key, $idx) {
            foreach ($groups as $g) {
                if (! empty($g['deleted'])) {
                    continue;
                }
                foreach ($g['days'] ?? [] as $d) {
                    if ($self && $g['key'] === $key && (int) $d['idx'] === $idx) {
                        continue;
                    }
                    if (($d[$field] ?? null) !== $value || empty($d['date'])) {
                        continue;
                    }
                    [$s, $e] = $this->span($d['date'], $d['start_time'] ?? null, $d['end_time'] ?? null);
                    if ($field === 'room_id') {
                        [$s, $e] = $this->roomSpan($s, $e);
                    }
                    if ($s < $to && $e > $from) {
                        return $label;
                    }
                }
            }

            return null;
        };
        if ($hit = $check($state['groups'], __('messages.saber.review.this_draft'), true)) {
            return $hit;
        }
        foreach (SaberRevision::with('program:id,title_ar,title_en')->whereIn('status', SaberRevision::OPEN)->where('program_id', '!=', $rev->program_id)->get() as $other) {
            if ($hit = $check($other->state['groups'] ?? [], __('messages.saber.review.other_draft', ['title' => $other->program?->title_ar ?: $other->program?->title_en]), false)) {
                return $hit;
            }
        }

        return null;
    }

    /** @return list<array{type: string, text: string}> */
    private function spread(SaberRevision $rev, array &$state, int $gi, int $from, string $field, string $value): array
    {
        $applied = 0;
        $skipped = [];
        foreach ($state['groups'][$gi]['days'] as $k => $d) {
            if ((int) $d['idx'] === $from || ($d[$field] ?? null) === $value || empty($d['date'])) {
                continue;
            }
            [$s, $e] = $this->span($d['date'], $d['start_time'] ?? null, $d['end_time'] ?? null);
            $holder = $field === 'room_id'
                ? $this->roomHolder($rev, $state, $value, $s, $e, $state['groups'][$gi]['key'], (int) $d['idx'])
                : $this->trainerHolder($rev, $state, $value, $s, $e, $state['groups'][$gi]['key'], (int) $d['idx']);
            if ($holder) {
                $skipped[] = (int) $d['idx'];

                continue;
            }
            $state['groups'][$gi]['days'][$k][$field] = $value;
            $applied++;
        }
        if ($applied === 0 && $skipped === []) {
            return [];
        }
        $kind = $field === 'room_id' ? 'room' : 'trainer';

        return [['type' => $skipped ? 'warning' : 'success', 'text' => $skipped
            ? __("messages.saber.{$kind}_spread_skipped", ['n' => $applied, 'days' => implode('، ', $skipped)])
            : __("messages.saber.{$kind}_spread", ['n' => $applied])]];
    }

    private function assertEditable(Program $program, array $group, int $idx): void
    {
        if (! empty($group['deleted']) || ($group['status'] ?? null) === TrainingGroup::CANCELLED) {
            throw new BusinessRuleException(__('messages.saber.group_cancelled'), 'group_cancelled');
        }
        if (! empty($group['live'])) {
            $live = collect($this->bench->days(TrainingGroup::find($group['key']) ?? new TrainingGroup, 0))->firstWhere('idx', $idx);
            if (! empty($live['locked'])) {
                throw new BusinessRuleException(__('messages.saber.day_has_attendance', ['n' => $idx]), 'day_has_attendance');
            }
        }
    }

    private function save(SaberRevision $rev, array $state, User $actor): SaberRevision
    {
        $rev->update(['state' => $state, 'updated_by' => $actor->id]);

        return $rev;
    }

    private function index(array $state, string $key): int
    {
        foreach ($state['groups'] as $i => $g) {
            if ($g['key'] === $key) {
                return $i;
            }
        }
        abort(404);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function span(string $date, ?string $start, ?string $end): array
    {
        $tz = config('app.timezone');
        $s = $this->day->all();

        return [CarbonImmutable::parse("{$date} ".($start ?: $s['day_start']), $tz), CarbonImmutable::parse("{$date} ".($end ?: $s['day_end']), $tz)];
    }

    /** With «one session per room per day» a room is taken for the whole day. */
    private function roomSpan(CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! $this->day->oneSessionPerRoomPerDay()) {
            return [$start, $end];
        }
        $d = $start->timezone(config('app.timezone'));

        return [$d->startOfDay(), $d->endOfDay()];
    }

    /** The comparable part of a day. */
    private function dayOf(array $d): array
    {
        return empty($d['date']) ? [] : ['idx' => (int) $d['idx'], 'date' => $d['date'], 'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null, 'room_id' => $d['room_id'] ?? null, 'trainer_id' => $d['trainer_id'] ?? null];
    }

    private function roomName(string $id): string
    {
        $r = TrainingRoom::find($id);

        return $r ? ($r->name_ar ?: $r->name_en) : '';
    }

    private function tellAuthor(SaberRevision $rev, string $type, array $title, ?string $note): void
    {
        $to = collect([$rev->created_by, $rev->submitted_by])->filter()->unique()->all();
        if ($to === []) {
            return;
        }
        $p = $rev->program;
        $this->notifications->broadcast($to, $type, $title, ['ar' => "«{$p->title_ar}»".($note ? " — {$note}" : ''), 'en' => "“{$p->title_en}”".($note ? " — {$note}" : '')], ['revision_id' => $rev->id, 'program_id' => $p->id, 'route' => '/admin/saber']);
    }

    /** The registered people of groups whose schedule changed are told, with their managers. */
    private function tellRegistrants(Program $program, array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }
        $ids = Registration::with('employee:id,user_id')->whereIn('training_group_id', $groupIds)->whereIn('status', [Registration::STATUS_PENDING, Registration::STATUS_APPROVED, Registration::STATUS_WAITLISTED])
            ->get()->pluck('employee.user_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $this->notifications->broadcast($ids, 'group.schedule_changed', ['ar' => 'تغيّر جدول مجموعتك التدريبية', 'en' => 'Your training group schedule changed'],
            ['ar' => "تحقق من المواعيد والقاعة الجديدة لبرنامج «{$program->title_ar}».", 'en' => "Check the new dates and room of “{$program->title_en}”."], ['program_id' => $program->id, 'route' => '/training']);
    }
}
