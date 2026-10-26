<?php

namespace App\Services\Saber;

use App\Exceptions\BusinessRuleException;
use App\Models\CalendarApproval;
use App\Models\CalendarDay;
use App\Models\CalendarRequest;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\NotificationService;
use App\Services\RoomService;
use App\Services\TrainerService;
use App\Services\TrainingDaySettings;
use App\Services\TrainingGroupService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * سابر+ scheduling tools (docs/saber/02-scheduling-tools.md, B1–B3): the plan of the coming days for a group, cancelling and reactivating a
 * programme or a group, and requests to train on a closed day.
 */
class Scheduling
{
    /** Group statuses a cancellation can be undone to (anything else comes back as planned). */
    private const RESTORABLE = [TrainingGroup::REGISTRATION_OPEN, TrainingGroup::ONGOING, TrainingGroup::POSTPONED, TrainingGroup::INCOMPLETE];

    public function __construct(
        private readonly Workbench $bench,
        private readonly CalendarService $calendar,
        private readonly RoomService $rooms,
        private readonly TrainerService $trainers,
        private readonly TrainingDaySettings $day,
        private readonly TrainingGroupService $groups,
        private readonly NotificationService $notifications,
    ) {}

    // ── B1 · plan of the coming days ─────────────────────────────────────────────────────────────────────────────

    /**
     * Every `every` weeks on `weekday` from `from`, `count` dates: each one free, busy (the room or trainer is taken by another group) or off
     * (closed for training). Nothing is saved.
     *
     * @param  array{weekday: int, every: int, count: int, from: string, room_id?: ?string, trainer_id?: ?string, start_time?: ?string, end_time?: ?string}  $o
     * @return list<array{date: string, status: string, label: ?string, allow_events: bool, busy: list<string>}>
     */
    public function preview(TrainingGroup $group, array $o): array
    {
        $tz = config('app.timezone');
        $settings = $this->day->all();
        $own = $group->sessions()->pluck('id')->all();
        $date = CarbonImmutable::parse($o['from'], $tz)->startOfDay();
        while ($date->dayOfWeek !== (int) $o['weekday']) {
            $date = $date->addDay();
        }
        $out = [];
        for ($i = 0; $i < $o['count']; $i++, $date = $date->addWeeks(max(1, (int) $o['every']))) {
            $key = $date->toDateString();
            $start = CarbonImmutable::parse($key.' '.($o['start_time'] ?? $settings['day_start']), $tz);
            $end = CarbonImmutable::parse($key.' '.($o['end_time'] ?? $settings['day_end']), $tz);
            $day = $this->calendar->describe($date, CalendarDay::whereDate('date', $key)->first(), CalendarApproval::whereDate('date', $key)->first());
            $busy = [];
            if (! empty($o['room_id'])) {
                $holders = collect($this->rooms->occupants($o['room_id'], $start, $end))->reject(fn ($h) => $h['type'] === 'session' && in_array($h['id'], $own, true));
                if ($holders->isNotEmpty()) {
                    $busy[] = __('messages.saber.plan.room_busy', ['by' => $holders->first()['program'] ?? $holders->first()['title']]);
                }
            }
            if (! empty($o['trainer_id']) && $this->trainers->conflicts($o['trainer_id'], $start, $end)->reject(fn ($s) => in_array($s->id, $own, true))->isNotEmpty()) {
                $busy[] = __('messages.saber.plan.trainer_busy');
            }
            $label = $day['entry']['title'] ?? $day['weekly']['title'] ?? ($day['training_allowed'] ? null : __('messages.saber.plan.kind_'.$day['kind']));
            $out[] = [
                'date' => $key, 'status' => ! $day['training_allowed'] ? 'off' : ($busy ? 'busy' : 'free'),
                'label' => $label, 'allow_events' => (bool) $day['allow_events'], 'weekly' => $day['weekly'] !== null, 'busy' => $busy,
            ];
        }

        return $out;
    }

    /**
     * Replaces the group's days with the chosen dates (day 1 = the earliest), with the room and trainer when they are free that day.
     *
     * @param  list<string>  $dates
     * @return list<array{type: string, text: string}>
     */
    public function applyPlan(TrainingGroup $group, array $dates, ?string $roomId, ?string $trainerId, User $actor): array
    {
        $dates = collect($dates)->unique()->sort()->values();
        $closed = $dates->filter(fn ($d) => ! $this->calendar->describe(CarbonImmutable::parse($d), CalendarDay::whereDate('date', $d)->first(), CalendarApproval::whereDate('date', $d)->first())['training_allowed']);
        if ($closed->isNotEmpty()) {
            throw new BusinessRuleException(__('messages.calendar.closed_for_training', ['dates' => $closed->implode(', ')]), 'calendar_closed', ['dates' => $closed->values()->all()]);
        }
        if ($group->sessions()->whereHas('attendance')->exists()) {
            throw new BusinessRuleException(__('messages.saber.plan.has_attendance'), 'day_has_attendance');
        }

        return DB::transaction(function () use ($group, $dates, $roomId, $trainerId, $actor) {
            $group->sessions()->get()->each->delete();
            $group->update(['default_room_id' => null]);
            $notices = [];
            foreach ($dates as $i => $date) {
                $patch = ['date' => $date] + array_filter(['room_id' => $roomId, 'trainer_id' => $trainerId]);
                try {
                    $notices = [...$notices, ...$this->bench->setDay($group->fresh(), $i + 1, $patch, $actor)];
                } catch (BusinessRuleException $e) {
                    if (in_array($e->errorCode, ['calendar_closed', 'outside_training_day', 'end_before_start'], true)) {
                        throw $e;
                    }
                    // The room or the trainer is taken that day: keep the date, say what could not be given.
                    $this->bench->setDay($group->fresh(), $i + 1, ['date' => $date, 'room_id' => null, 'trainer_id' => null], $actor);
                    $notices[] = ['type' => 'warning', 'text' => __('messages.saber.plan.resource_skipped', ['n' => $i + 1, 'date' => $date])];
                }
            }
            $notices[] = ['type' => 'success', 'text' => __('messages.saber.plan.applied', ['n' => $dates->count()])];

            return $this->squash($notices);
        });
    }

    // ── B2 · cancellation and reactivation ───────────────────────────────────────────────────────────────────────

    /** @param array{reason: string, requested_by: string, attachment_path?: ?string} $d */
    public function cancelGroup(TrainingGroup $group, array $d, User $actor, ?Carbon $at = null, bool $cascade = true): void
    {
        if (in_array($group->status, [TrainingGroup::CANCELLED, TrainingGroup::COMPLETED], true)) {
            throw new BusinessRuleException(__('messages.saber.cancel.not_cancellable'), 'not_cancellable');
        }
        $at ??= now();
        DB::transaction(function () use ($group, $d, $actor, $at, $cascade) {
            $snapshot = $group->sessions()->where('status', '!=', 'cancelled')->get()
                ->map(fn (ProgramSession $s) => ['session_id' => $s->id, 'room_id' => $s->training_room_id, 'trainer_id' => $s->trainer_id, 'status' => $s->status])->values()->all();
            $group->update([
                'status_before_cancel' => $group->status, 'cancel_snapshot' => $snapshot, 'cancel_requested_by' => $d['requested_by'],
                'cancel_attachment_path' => $d['attachment_path'] ?? null, 'cancelled_at' => $at, 'cancelled_by' => $actor->id,
            ]);
            $this->groups->transition($group->fresh(), TrainingGroup::CANCELLED, $d['reason'], null, $actor);

            // The last running group of a programme takes the programme with it.
            $program = $group->program()->first();
            if ($cascade && $program && $program->status !== Program::STATUS_CANCELLED && $program->groups()->where('status', '!=', TrainingGroup::CANCELLED)->doesntExist()) {
                $this->markProgramCancelled($program, $d, $actor, $at);
            }
        });
    }

    /** @param array{reason: string, requested_by: string, attachment_path?: ?string} $d */
    public function cancelProgram(Program $program, array $d, User $actor): void
    {
        if ($program->status === Program::STATUS_CANCELLED) {
            throw new BusinessRuleException(__('messages.saber.cancel.not_cancellable'), 'not_cancellable');
        }
        $at = now();
        DB::transaction(function () use ($program, $d, $actor, $at) {
            foreach ($program->groups()->whereNotIn('status', [TrainingGroup::CANCELLED, TrainingGroup::COMPLETED])->get() as $group) {
                $this->cancelGroup($group, $d, $actor, $at, false);
            }
            $this->markProgramCancelled($program->fresh(), $d, $actor, $at);
        });
    }

    /** @return list<array{type: string, text: string}> */
    public function reactivateGroup(TrainingGroup $group, User $actor, bool $withProgram = true): array
    {
        if ($group->status !== TrainingGroup::CANCELLED) {
            throw new BusinessRuleException(__('messages.saber.cancel.not_cancelled'), 'not_cancelled');
        }

        return DB::transaction(function () use ($group, $actor, $withProgram) {
            $notices = $this->restoreSessions($group);
            $this->groups->transition($group, TrainingGroup::PLANNED, null, null, $actor);
            $back = in_array($group->status_before_cancel, self::RESTORABLE, true) ? $group->status_before_cancel : TrainingGroup::PLANNED;
            $group->refresh()->update([
                'status' => $back, 'status_reason' => null, 'status_before_cancel' => null, 'cancel_snapshot' => null, 'cancel_requested_by' => null,
                'cancel_attachment_path' => null, 'cancelled_at' => null, 'cancelled_by' => null,
            ]);
            $program = $group->program()->first();
            if ($withProgram && $program?->status === Program::STATUS_CANCELLED) {
                $this->restoreProgram($program);
            }

            return $notices;
        });
    }

    /** @return list<array{type: string, text: string}> */
    public function reactivateProgram(Program $program, User $actor): array
    {
        if ($program->status !== Program::STATUS_CANCELLED) {
            throw new BusinessRuleException(__('messages.saber.cancel.not_cancelled'), 'not_cancelled');
        }

        return DB::transaction(function () use ($program, $actor) {
            $notices = [];
            // The groups cancelled with the programme come back with it; a group cancelled on its own earlier stays cancelled.
            $groups = $program->groups()->where('status', TrainingGroup::CANCELLED)->get()
                ->filter(fn (TrainingGroup $g) => $program->cancelled_at && $g->cancelled_at && abs($g->cancelled_at->diffInSeconds($program->cancelled_at)) < 5);
            foreach ($groups as $group) {
                $notices = [...$notices, ...$this->reactivateGroup($group, $actor, false)];
            }
            $this->restoreProgram($program->fresh());

            return $this->squash($notices);
        });
    }

    // ── B3 · training on a closed day ────────────────────────────────────────────────────────────────────────────

    /** A request (or, for whoever approves the calendar and asks for it, an immediate approval that opens the day). */
    public function requestClosedDay(string $date, ?TrainingGroup $group, ?int $idx, string $reason, User $actor, bool $instant): CalendarRequest
    {
        $day = CarbonImmutable::parse($date);
        if ($this->calendar->describe($day, CalendarDay::whereDate('date', $date)->first(), CalendarApproval::whereDate('date', $date)->first())['training_allowed']) {
            throw new BusinessRuleException(__('messages.calendar.not_closed'), 'calendar_not_closed');
        }
        $pending = CalendarRequest::whereDate('date', $date)->where('status', CalendarRequest::PENDING)->where('training_group_id', $group?->id)->first();
        if ($pending && ! $instant) {
            throw new BusinessRuleException(__('messages.saber.offday.already_requested'), 'already_requested');
        }
        $request = $pending ?? CalendarRequest::create([
            'date' => $date, 'program_id' => $group?->program_id, 'training_group_id' => $group?->id, 'day_idx' => $idx, 'reason' => $reason,
            'status' => CalendarRequest::PENDING, 'requested_by' => $actor->id,
        ]);
        if ($instant && $actor->hasPermission('calendar.approve')) {
            return $this->decide($request, true, __('messages.saber.offday.instant'), $actor);
        }
        $approvers = User::where('status', 'active')->where('id', '!=', $actor->id)
            ->whereHas('roles', fn ($q) => $q->where('slug', 'super_admin')->orWhereHas('permissions', fn ($p) => $p->where('slug', 'calendar.approve')))->pluck('id');
        if ($approvers->isNotEmpty()) {
            $program = $group?->program;
            $this->notifications->broadcast($approvers, 'calendar.request',
                ['ar' => 'طلب حجز في يوم إجازة', 'en' => 'Request to train on a closed day'],
                ['ar' => "{$actor->displayName('ar')} يطلب التدريب يوم {$date}".($program ? " لبرنامج «{$program->title_ar}»" : '')." — {$reason}", 'en' => "{$actor->displayName('en')} asks to train on {$date}".($program ? " for “{$program->title_en}”" : '')." — {$reason}"],
                ['request_id' => $request->id, 'route' => '/admin/saber?inbox=1'],
            );
        }

        return $request;
    }

    public function decide(CalendarRequest $request, bool $approve, ?string $note, User $actor): CalendarRequest
    {
        if ($request->status !== CalendarRequest::PENDING) {
            throw new BusinessRuleException(__('messages.saber.offday.decided'), 'already_decided');
        }
        DB::transaction(function () use ($request, $approve, $note, $actor) {
            $request->update(['status' => $approve ? CalendarRequest::APPROVED : CalendarRequest::REJECTED, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            if ($approve) {
                $day = CarbonImmutable::parse($request->date->toDateString());
                $this->calendar->approveSpan($day, $day, $request->reason.($note ? " — {$note}" : ''), $actor->id);
            }
        });
        if ($request->requested_by && $request->requested_by !== $actor->id) {
            $date = $request->date->toDateString();
            $this->notifications->send($request->requested_by, 'calendar.request_decided',
                $approve ? ['ar' => 'اعتُمد طلب الحجز في يوم الإجازة', 'en' => 'Your closed-day request was approved'] : ['ar' => 'رُفض طلب الحجز في يوم الإجازة', 'en' => 'Your closed-day request was rejected'],
                $approve ? ['ar' => "أصبح يوم {$date} متاحاً للجدولة.", 'en' => "{$date} is now open for scheduling."] : ['ar' => "لن يُسمح بالحجز يوم {$date}.".($note ? " {$note}" : ''), 'en' => "Booking on {$date} is not allowed.".($note ? " {$note}" : '')],
                ['request_id' => $request->id, 'route' => '/admin/saber'],
            );
        }

        return $request->fresh(['program', 'trainingGroup', 'requester', 'decider']);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function markProgramCancelled(Program $program, array $d, User $actor, Carbon $at): void
    {
        $program->update([
            'status_before_cancel' => $program->status, 'status' => Program::STATUS_CANCELLED, 'cancel_reason' => $d['reason'], 'cancel_requested_by' => $d['requested_by'],
            'cancel_attachment_path' => $d['attachment_path'] ?? null, 'cancelled_at' => $at, 'cancelled_by' => $actor->id,
        ]);
    }

    private function restoreProgram(Program $program): void
    {
        $back = $program->status_before_cancel && $program->status_before_cancel !== Program::STATUS_CANCELLED ? $program->status_before_cancel : Program::STATUS_PUBLISHED;
        // A programme with a single group keeps that group in step on save; save the group's own status again afterwards.
        $groupStatus = $program->groups()->count() === 1 ? $program->groups()->value('status') : null;
        $program->update([
            'status' => $back, 'status_before_cancel' => null, 'cancel_reason' => null, 'cancel_requested_by' => null, 'cancel_attachment_path' => null, 'cancelled_at' => null, 'cancelled_by' => null,
        ]);
        if ($groupStatus && $groupStatus !== TrainingGroup::CANCELLED) {
            $program->groups()->update(['status' => $groupStatus]);
        }
    }

    /** Brings the group's days back with their room and trainer when those are still free; says which ones were taken meanwhile. */
    private function restoreSessions(TrainingGroup $group): array
    {
        $notices = [];
        foreach ((array) $group->cancel_snapshot as $snap) {
            $s = ProgramSession::find($snap['session_id'] ?? null);
            if (! $s || $s->status !== 'cancelled') {
                continue;
            }
            $room = $snap['room_id'] ?? null;
            $trainer = $snap['trainer_id'] ?? null;
            $date = $s->starts_at->timezone(config('app.timezone'))->toDateString();
            if ($room && ! $this->rooms->isFree($room, $s->starts_at, $s->ends_at, $s->id)) {
                $notices[] = ['type' => 'warning', 'text' => __('messages.saber.cancel.room_taken', ['date' => $date])];
                $room = null;
            }
            if ($trainer && $this->trainers->conflicts($trainer, $s->starts_at, $s->ends_at, $s->id)->isNotEmpty()) {
                $notices[] = ['type' => 'warning', 'text' => __('messages.saber.cancel.trainer_taken', ['date' => $date])];
                $trainer = null;
            }
            $s->update(['status' => $snap['status'] ?? 'scheduled', 'training_room_id' => $room, 'trainer_id' => $trainer]);
        }

        return $notices;
    }

    /** One line per kind of warning repeated day after day is enough. @param list<array{type: string, text: string}> $notices */
    private function squash(array $notices): array
    {
        return array_values(collect($notices)->unique('text')->all());
    }
}
