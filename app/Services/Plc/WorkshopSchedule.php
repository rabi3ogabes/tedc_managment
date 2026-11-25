<?php

namespace App\Services\Plc;

use App\Exceptions\BusinessRuleException;
use App\Models\GroupTrainer;
use App\Models\PlcSessionTrainer;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\NotificationService;
use App\Services\RoomService;
use App\Services\TrainerService;
use App\Services\TrainingDaySettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PLC Phase 4: the workshop's sessions on the programme engine. Each session has its own time, delivery mode (in person
 * with a room or a venue at the host school, remote with a protected link, or hybrid with both) and trainers drawn from
 * the workshop's confirmed trainers. Every plan is checked against the training calendar, the training-day window, the
 * room, the trainers' other sessions and the workshop's own sessions; alternatives are suggested and never applied by
 * themselves. Once the people were invited (state scheduled or later), a change needs a reason, is recorded as a
 * decision and tells everyone affected. "Scheduled" is reached only when every session is complete, and sends the
 * invitations.
 */
class WorkshopSchedule
{
    public const MODES = ['in_person', 'online', 'hybrid'];

    public const PLANNABLE = ['draft', 'submitted', 'needs_changes', 'approved', 'scheduled', 'ready'];

    public const INVITED = ['scheduled', 'ready', 'in_progress'];

    public const MAX_MINUTES = 12 * 60;

    public function __construct(
        private readonly WorkshopPeople $people,
        private readonly WorkshopWorkflow $workflow,
        private readonly CalendarService $calendar,
        private readonly RoomService $rooms,
        private readonly TrainerService $trainers,
        private readonly TrainingDaySettings $day,
        private readonly NotificationService $notifications,
    ) {}

    // Reading ------------------------------------------------------------------------------------------------------

    /** The sessions with their trainers, what each still lacks, and whether the workshop can be declared scheduled. */
    public function overview(PlcWorkshop $w, User $user): array
    {
        $rows = $this->sessions($w)->map(fn (ProgramSession $s) => $this->present($s, $user))->values();
        $missing = $rows->flatMap(fn ($r) => collect($r['missing'])->map(fn ($m) => ['session' => $r['id'], 'sequence' => $r['sequence'], 'issue' => $m]))->values()->all();
        $invited = in_array($w->workflow_state, self::INVITED, true);

        return [
            'sessions' => $rows->all(),
            'readiness' => ['sessions' => $rows->count(), 'complete' => $rows->isNotEmpty() && ! $missing, 'missing' => $missing],
            'invited' => $invited,
            'can' => [
                'plan' => in_array($w->workflow_state, self::PLANNABLE, true) && $this->mayPlan($user),
                'schedule' => $w->workflow_state === 'approved' && $rows->isNotEmpty() && ! $missing && $this->mayPlan($user),
                'change_needs_reason' => $invited,
            ],
            'conflicts' => $this->conflicts($w),
            'trainers' => $this->confirmedTrainers($w)->map(fn (GroupTrainer $gt) => ['trainer_id' => $gt->trainer_id, 'name' => $gt->trainer?->name_ar, 'name_en' => $gt->trainer?->name_en, 'role' => $gt->role === 'lead' ? 'lead' : 'co'])->values()->all(),
            'program' => ['delivery_mode' => $w->program->delivery_mode, 'start_date' => $w->program->start_date?->toDateString(), 'end_date' => $w->program->end_date?->toDateString(), 'capacity' => $w->program->capacity],
        ];
    }

    public function sessions(PlcWorkshop $w): Collection
    {
        return ProgramSession::with(['room:id,name_ar,name_en,capacity', 'plcTrainers.trainer:id,name_ar,name_en'])->where('program_id', $w->program_id)->orderBy('starts_at')->get();
    }

    private function present(ProgramSession $s, User $user): array
    {
        $trainers = $s->plcTrainers->map(fn (PlcSessionTrainer $t) => ['trainer_id' => $t->trainer_id, 'name' => $t->trainer?->name_ar, 'name_en' => $t->trainer?->name_en, 'role' => $t->role, 'status' => $t->status])->values()->all();
        $mode = $s->mode ?: 'in_person';
        $missing = [];
        if (in_array($mode, ['in_person', 'hybrid'], true) && ! $s->training_room_id && ! $s->location_text) {
            $missing[] = 'venue';
        }
        if (in_array($mode, ['online', 'hybrid'], true) && ! $s->online_url) {
            $missing[] = 'link';
        }
        if (! $trainers) {
            $missing[] = 'trainer';
        }
        $manager = $this->mayPlan($user);

        return [
            'id' => $s->id, 'sequence' => $s->sequence, 'title_ar' => $s->title_ar, 'title_en' => $s->title_en, 'description' => $s->description,
            'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'minutes' => $s->starts_at && $s->ends_at ? $s->starts_at->diffInMinutes($s->ends_at) : null, 'status' => $s->status, 'mode' => $mode,
            'room' => $s->room ? ['id' => $s->room->id, 'name' => $s->room->name_ar, 'name_en' => $s->room->name_en, 'capacity' => $s->room->capacity] : null, 'location_text' => $s->location_text,
            'online' => ['platform' => $s->online_platform, 'has_link' => (bool) $s->online_url, 'url' => $manager ? $s->online_url : null, 'passcode' => $manager ? $s->online_passcode : null, 'protected' => (bool) $s->online_passcode],
            'checkin_window_minutes' => $s->checkin_window_minutes, 'checkout_window_minutes' => $s->checkout_window_minutes,
            'trainers' => $trainers, 'missing' => $missing,
        ];
    }

    // Planning -----------------------------------------------------------------------------------------------------

    /**
     * Creates or changes a session. After the invitations went out, `reason` is required and the change is recorded
     * and announced.
     *
     * @param  array<string, mixed>  $d
     */
    public function plan(PlcWorkshop $w, User $by, array $d, ?ProgramSession $session = null): ProgramSession
    {
        $this->assertPlannable($w, $by);
        $invited = in_array($w->workflow_state, self::INVITED, true);
        if ($invited && trim((string) ($d['reason'] ?? '')) === '') {
            throw new BusinessRuleException(__('messages.plc.schedule_reason'), 'plc_reason_required');
        }
        $program = $w->program;
        $start = Carbon::parse($d['starts_at']);
        $end = Carbon::parse($d['ends_at']);
        if ($end->lte($start) || $start->diffInMinutes($end) > self::MAX_MINUTES) {
            throw new BusinessRuleException(__('messages.plc.session_span'), 'plc_session_span');
        }
        $mode = in_array($d['mode'] ?? null, self::MODES, true) ? $d['mode'] : ($session?->mode ?: ($program->delivery_mode ?: 'in_person'));
        $roomId = in_array($mode, ['in_person', 'hybrid'], true) ? ($d['training_room_id'] ?? null) : null;
        $trainerIds = array_values(array_unique(array_map('strval', (array) ($d['trainer_ids'] ?? []))));
        $this->assertTrainersConfirmed($w, $trainerIds);

        // The workshop's own sessions first, then the calendar, the training-day window, the room and the trainers.
        $overlap = ProgramSession::where('program_id', $program->id)->where('status', '!=', 'cancelled')->when($session, fn ($q) => $q->where('id', '!=', $session->id))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->first();
        if ($overlap) {
            throw new BusinessRuleException(__('messages.plc.session_overlap', ['n' => $overlap->sequence]), 'plc_session_overlap', ['session_id' => $overlap->id]);
        }

        if (! empty($d['calendar_approval_reason']) && $by->hasPermission('calendar.approve')) {
            $this->calendar->approveSpan($start, $end, (string) $d['calendar_approval_reason'], $by->id);
        }
        $this->calendar->assertTrainingAllowed($start, $end);
        $this->day->assertWithinDay($start, $end);
        if ($roomId) {
            $attendees = Registration::where('program_id', $program->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING])->count();
            $this->rooms->assertBookable($roomId, $start, $end, $session?->id, $attendees ?: null);
        }
        $overrides = [];
        foreach ($trainerIds as $tid) {
            try {
                $this->trainers->assertAssignable($tid, $start, $end, $session?->id);
            } catch (BusinessRuleException $e) {
                if ($e->errorCode !== 'trainer_conflict' || empty($d['force'])) {
                    throw $e;
                }
                if (trim((string) ($d['force_reason'] ?? '')) === '') {
                    throw new BusinessRuleException(__('messages.plc.force_reason'), 'plc_force_reason');
                }
                $overrides[] = $tid;
            }
        }

        return DB::transaction(function () use ($w, $by, $d, $session, $program, $start, $end, $mode, $roomId, $trainerIds, $overrides, $invited) {
            $group = $this->people->group($w);
            $sequence = $session?->sequence ?? ((int) ProgramSession::where('program_id', $program->id)->max('sequence') + 1);
            $lead = GroupTrainer::where('group_id', $group->id)->whereIn('trainer_id', $trainerIds)->where('status', 'approved')->orderByRaw("case role when 'lead' then 0 else 1 end")->value('trainer_id') ?? ($trainerIds[0] ?? null);
            $attrs = [
                'program_id' => $program->id, 'training_group_id' => $group->id, 'sequence' => $sequence,
                'title_ar' => trim((string) ($d['title_ar'] ?? '')) ?: ($session?->title_ar ?: "الجلسة {$sequence}"), 'title_en' => trim((string) ($d['title_en'] ?? '')) ?: ($session?->title_en ?: "Session {$sequence}"),
                'description' => $d['description'] ?? $session?->description, 'starts_at' => $start, 'ends_at' => $end, 'mode' => $mode,
                'training_room_id' => $roomId, 'location_text' => in_array($mode, ['in_person', 'hybrid'], true) ? ($d['location_text'] ?? $session?->location_text) : null,
                'online_url' => in_array($mode, ['online', 'hybrid'], true) ? ($d['online_url'] ?? $session?->online_url) : null,
                'online_platform' => in_array($mode, ['online', 'hybrid'], true) ? ($d['online_platform'] ?? $session?->online_platform) : null,
                'online_passcode' => in_array($mode, ['online', 'hybrid'], true) ? ($d['online_passcode'] ?? $session?->online_passcode) : null,
                'checkin_window_minutes' => $d['checkin_window_minutes'] ?? $session?->checkin_window_minutes, 'checkout_window_minutes' => $d['checkout_window_minutes'] ?? $session?->checkout_window_minutes,
                'trainer_id' => $lead, 'status' => 'scheduled',
            ];
            $before = $session ? ['starts_at' => $session->starts_at, 'ends_at' => $session->ends_at, 'room' => $session->training_room_id, 'mode' => $session->mode] : null;
            $session = $session ? tap($session)->update($attrs) : ProgramSession::create($attrs);
            // Trainers per session.
            $roles = GroupTrainer::where('group_id', $group->id)->whereIn('trainer_id', $trainerIds)->pluck('role', 'trainer_id');
            PlcSessionTrainer::where('program_session_id', $session->id)->whereNotIn('trainer_id', $trainerIds)->delete();
            foreach ($trainerIds as $tid) {
                PlcSessionTrainer::updateOrCreate(['program_session_id' => $session->id, 'trainer_id' => $tid], ['role' => ($roles[$tid] ?? 'co') === 'lead' ? 'lead' : 'co', 'status' => 'planned', 'minutes' => $start->diffInMinutes($end)]);
            }
            // The programme's dates stretch to hold its sessions.
            $extended = $this->extendDates($w);
            foreach ($overrides as $tid) {
                $this->workflow->decision($w, 'exception', 'trainer_double_booking_overridden', (string) $d['force_reason'], ['session_id' => $session->id, 'trainer_id' => $tid], $by);
            }
            $this->workflow->decision($w, 'session', $before ? 'updated' : 'planned', $invited ? (string) $d['reason'] : null, ['session_id' => $session->id, 'sequence' => $sequence, 'before' => $before, 'extended' => $extended], $by);
            if ($invited) {
                $this->announce($w, 'plc.schedule_changed', ['session_id' => $session->id], (string) $d['reason']);
            }

            return $session->fresh(['room', 'plcTrainers.trainer']);
        });
    }

    /** Removes a planned session; after invitations it is cancelled with a reason and everyone is told. */
    public function remove(PlcWorkshop $w, User $by, ProgramSession $session, ?string $reason = null): void
    {
        $this->assertPlannable($w, $by);
        abort_unless($session->program_id === $w->program_id, 404);
        $invited = in_array($w->workflow_state, self::INVITED, true);
        if ($invited && trim((string) $reason) === '') {
            throw new BusinessRuleException(__('messages.plc.schedule_reason'), 'plc_reason_required');
        }
        DB::transaction(function () use ($w, $by, $session, $reason, $invited) {
            $this->workflow->decision($w, 'session', $invited ? 'cancelled' : 'removed', $reason, ['session_id' => $session->id, 'sequence' => $session->sequence, 'starts_at' => $session->starts_at], $by);
            if ($invited) {
                $session->update(['status' => 'cancelled']);
                $this->announce($w, 'plc.session_cancelled', ['session_id' => $session->id], (string) $reason);
            } else {
                $session->delete();
            }
        });
    }

    /**
     * Alternative slots for a requested span, never applied by themselves: the same time of day and length on the
     * next training days where the room (when given) and the trainers are free and the workshop has nothing else.
     * Also rooms free for the requested span.
     */
    public function alternatives(PlcWorkshop $w, array $d, ?ProgramSession $except = null): array
    {
        $start = Carbon::parse($d['starts_at']);
        $end = Carbon::parse($d['ends_at']);
        $minutes = max(15, $start->diffInMinutes($end));
        $roomId = $d['training_room_id'] ?? null;
        $trainerIds = array_values(array_unique(array_map('strval', (array) ($d['trainer_ids'] ?? []))));
        $closed = $this->calendar->closedDates($start->copy()->startOfDay(), $start->copy()->addDays(21)->endOfDay())->pluck('date')->flip();
        $slots = [];
        for ($i = 1; $i <= 21 && count($slots) < 5; $i++) {
            $s = $start->copy()->addDays($i);
            $e = $s->copy()->addMinutes($minutes);
            if ($closed->has($s->toDateString())) {
                continue;
            }
            $busy = ProgramSession::where('program_id', $w->program_id)->where('status', '!=', 'cancelled')->when($except, fn ($q) => $q->where('id', '!=', $except->id))->where('starts_at', '<', $e)->where('ends_at', '>', $s)->exists();
            if ($busy || ($roomId && ! $this->rooms->isFree($roomId, $s, $e, $except?->id))) {
                continue;
            }
            $free = true;
            foreach ($trainerIds as $tid) {
                if ($this->trainers->conflicts($tid, $s, $e, $except?->id)->isNotEmpty()) {
                    $free = false;
                    break;
                }
            }
            if ($free) {
                $slots[] = ['starts_at' => $s, 'ends_at' => $e, 'date' => $s->toDateString()];
            }
        }
        $rooms = $this->rooms->suggest($start, $end, ['capacity' => (int) ($w->program->capacity ?? 0)], $except?->id)
            ->filter(fn ($r) => $r['available'] && $r['fits_capacity'])->sortByDesc('score')->take(5)
            ->map(fn ($r) => ['id' => $r['room']->id, 'name' => $r['room']->name_ar, 'name_en' => $r['room']->name_en, 'capacity' => $r['room']->capacity])->values()->all();

        return ['slots' => $slots, 'rooms' => $rooms];
    }

    /** The conflicts panel: for every session, what collides (room, trainers, closed day, trainees' other sessions). */
    public function conflicts(PlcWorkshop $w): array
    {
        $out = [];
        $employeeIds = Registration::where('program_id', $w->program_id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING])->pluck('employee_id');
        foreach ($this->sessions($w)->where('status', '!=', 'cancelled') as $s) {
            $issues = [];
            if ($this->calendar->closedDates($s->starts_at, $s->ends_at)->isNotEmpty()) {
                $issues[] = ['kind' => 'closed_day'];
            }
            if ($s->training_room_id) {
                $clash = $this->rooms->conflicts($s->training_room_id, $s->starts_at, $s->ends_at, $s->id);
                if ($clash->isNotEmpty()) {
                    $issues[] = ['kind' => 'room', 'with' => $clash->map(fn ($o) => $o->translate('title'))->take(3)->values()->all()];
                }
            }
            foreach ($s->plcTrainers as $t) {
                $clash = $this->trainers->conflicts($t->trainer_id, $s->starts_at, $s->ends_at, $s->id);
                if ($clash->isNotEmpty()) {
                    $issues[] = ['kind' => 'trainer', 'trainer' => $t->trainer?->name_ar, 'with' => $clash->map(fn ($o) => $o->translate('title'))->take(3)->values()->all()];
                }
            }
            if ($employeeIds->isNotEmpty()) {
                $otherPrograms = ProgramSession::where('program_id', '!=', $w->program_id)->where('status', '!=', 'cancelled')->where('starts_at', '<', $s->ends_at)->where('ends_at', '>', $s->starts_at)->pluck('program_id')->unique();
                $n = $otherPrograms->isEmpty() ? 0 : Registration::whereIn('program_id', $otherPrograms)->whereIn('employee_id', $employeeIds)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->distinct('employee_id')->count('employee_id');
                if ($n) {
                    $issues[] = ['kind' => 'trainees', 'count' => $n];
                }
            }
            if ($issues) {
                $out[] = ['session_id' => $s->id, 'sequence' => $s->sequence, 'starts_at' => $s->starts_at, 'issues' => $issues];
            }
        }

        return $out;
    }

    // Scheduling ---------------------------------------------------------------------------------------------------

    /** approved → scheduled: every session complete; then the invitations go to the seated people and the trainers. */
    public function schedule(PlcWorkshop $w, User $by): array
    {
        $this->assertPlannable($w, $by);
        if ($w->workflow_state !== 'approved') {
            throw new BusinessRuleException(__('messages.plc.not_approved_for_schedule'), 'plc_bad_transition');
        }
        $o = $this->overview($w, $by);
        if (! $o['readiness']['complete']) {
            throw new BusinessRuleException(__('messages.plc.schedule_incomplete'), 'plc_schedule_incomplete', ['missing' => $o['readiness']['missing']]);
        }
        $this->workflow->move($w, 'scheduled', $by, null, ['sessions' => $o['readiness']['sessions']]);
        $sent = $this->announce($w, 'plc.scheduled', ['sessions' => $o['readiness']['sessions']]);
        $this->workflow->decision($w, 'invitations', 'sent', null, $sent, $by);

        return ['state' => $w->fresh()->workflow_state] + $sent;
    }

    // Calendar -----------------------------------------------------------------------------------------------------

    /** PLC sessions inside the person's scope for a span, with the calendar's closed days. */
    public function calendar(User $user, CarbonInterface $from, CarbonInterface $to, array $filters, WorkshopService $workshops): array
    {
        $programIds = $workshops->query($user)->pluck('plc_workshops.program_id');
        $q = ProgramSession::with(['program:id,code,title_ar,title_en,delivery_mode', 'program.plcWorkshop:program_id,workflow_state,scenario,host_school_id', 'program.plcWorkshop.hostSchool:id,name_ar,name_en,region', 'room:id,name_ar,name_en', 'plcTrainers.trainer:id,name_ar,name_en'])
            ->whereIn('program_id', $programIds)->where('status', '!=', 'cancelled')->where('starts_at', '<', $to->copy()->endOfDay())->where('ends_at', '>', $from->copy()->startOfDay())
            ->when($filters['mode'] ?? null, fn ($b, $v) => $b->where('mode', $v))
            ->when($filters['trainer_id'] ?? null, fn ($b, $v) => $b->whereHas('plcTrainers', fn ($x) => $x->where('trainer_id', $v)))
            ->when($filters['state'] ?? null, fn ($b, $v) => $b->whereHas('program.plcWorkshop', fn ($x) => $x->where('workflow_state', $v)))
            ->when($filters['school_id'] ?? null, fn ($b, $v) => $b->whereHas('program.plcWorkshop', fn ($x) => $x->where('host_school_id', $v)->orWhereHas('schools', fn ($y) => $y->where('school_id', $v))))
            ->when($filters['region'] ?? null, fn ($b, $v) => $b->whereHas('program.plcWorkshop.hostSchool', fn ($x) => $x->where('region', $v)))
            ->orderBy('starts_at')->get();

        return [
            'days' => collect($this->calendar->range($from, $to, false)['days'])->map(fn ($d) => ['date' => $d['date'], 'training_allowed' => $d['training_allowed'], 'kind' => $d['kind'] ?? null])->values()->all(),
            'sessions' => $q->map(fn (ProgramSession $s) => [
                'id' => $s->id, 'workshop_id' => $s->program_id, 'code' => $s->program?->code, 'title' => $s->program?->title_ar, 'title_en' => $s->program?->title_en, 'session_title' => $s->title_ar,
                'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'mode' => $s->mode ?: 'in_person', 'state' => $s->program?->plcWorkshop?->workflow_state, 'scenario' => $s->program?->plcWorkshop?->scenario,
                'school' => $s->program?->plcWorkshop?->hostSchool?->name_ar, 'region' => $s->program?->plcWorkshop?->hostSchool?->region, 'room' => $s->room?->name_ar,
                'trainers' => $s->plcTrainers->map(fn ($t) => $t->trainer?->name_ar)->filter()->values()->all(),
            ])->values()->all(),
        ];
    }

    // Helpers ------------------------------------------------------------------------------------------------------

    private function mayPlan(User $user): bool
    {
        return $user->hasPermission('plc.manage') || $user->hasPermission('plc.internal.submit');
    }

    private function assertPlannable(PlcWorkshop $w, User $by): void
    {
        if (! $this->mayPlan($by)) {
            abort(403);
        }
        if (! in_array($w->workflow_state, self::PLANNABLE, true)) {
            throw new BusinessRuleException(__('messages.plc.not_plannable'), 'plc_not_plannable');
        }
    }

    private function confirmedTrainers(PlcWorkshop $w): Collection
    {
        $group = $w->program->groups()->orderBy('sequence')->first();

        return $group ? GroupTrainer::with('trainer:id,name_ar,name_en,user_id')->where('group_id', $group->id)->where('status', 'approved')->get() : collect();
    }

    private function assertTrainersConfirmed(PlcWorkshop $w, array $trainerIds): void
    {
        if (! $trainerIds) {
            return;
        }
        $ok = $this->confirmedTrainers($w)->pluck('trainer_id');
        $unknown = array_values(array_diff($trainerIds, $ok->all()));
        if ($unknown) {
            throw new BusinessRuleException(__('messages.plc.trainer_not_assigned'), 'plc_trainer_not_assigned', ['trainer_ids' => $unknown]);
        }
    }

    /** Stretches the programme's (and group's) dates to cover its sessions; returns the new span when it changed. */
    private function extendDates(PlcWorkshop $w): ?array
    {
        $program = $w->program->fresh();
        $first = ProgramSession::where('program_id', $program->id)->where('status', '!=', 'cancelled')->min('starts_at');
        $last = ProgramSession::where('program_id', $program->id)->where('status', '!=', 'cancelled')->max('ends_at');
        if (! $first || ! $last) {
            return null;
        }
        $start = Carbon::parse($first)->startOfDay();
        $end = Carbon::parse($last)->startOfDay();
        $patch = [];
        if (! $program->start_date || $start->lt($program->start_date)) {
            $patch['start_date'] = $start;
        }
        if (! $program->end_date || $end->gt($program->end_date)) {
            $patch['end_date'] = $end;
        }
        if (! $patch) {
            return null;
        }
        $program->update($patch);
        $program->groups()->update(array_intersect_key($patch, array_flip(['start_date', 'end_date'])));

        return ['start_date' => $program->fresh()->start_date->toDateString(), 'end_date' => $program->fresh()->end_date->toDateString()];
    }

    /** Tells the seated people and the trainers; returns how many were told. */
    private function announce(PlcWorkshop $w, string $type, array $data = [], ?string $reason = null): array
    {
        $program = $w->program;
        $userIds = Registration::with('employee:id,user_id')->where('program_id', $program->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING])->get()->pluck('employee.user_id')->filter();
        $trainerUserIds = $this->confirmedTrainers($w)->pluck('trainer.user_id')->filter();
        $all = $userIds->merge($trainerUserIds)->unique()->values();
        $body = ['ar' => "«{$program->title_ar}»".($reason ? " — {$reason}" : ''), 'en' => '"'.($program->title_en ?: $program->title_ar).'"'.($reason ? " — {$reason}" : '')];
        $n = $all->isEmpty() ? 0 : $this->notifications->broadcast($all, $type, ['ar' => 'جدول ورشة مجتمع مهني', 'en' => 'PLC workshop schedule'], $body, ['program_id' => $program->id, 'route' => '/portal/training'] + $data);

        return ['notified' => $n, 'trainees' => $userIds->count(), 'trainers' => $trainerUserIds->count()];
    }
}
