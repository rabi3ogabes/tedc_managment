<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\GroupTrainer;
use App\Models\PlcSessionTrainer;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\SeatingPlan;
use App\Models\Trainer;
use App\Models\TrainerAttendance;
use App\Models\TrainingGroup;
use App\Models\TrainingKit;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Support\ProgramTypes;
use App\Support\RoomCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The live training board: every classroom of one day with the session it holds, its trainer and supervisor, the kit,
 * and the trainees who checked in. The board is drawn in 3D on the dashboard, so it carries only what the drawing needs;
 * the full training card of one classroom is a second, heavier read.
 */
class LiveTrainingService
{
    /** A trainee who checks out this many minutes before the end has left the room; later than that is the normal end of the day. */
    private const LEFT_EARLY_MINUTES = 5;

    /** A room whose next session starts within this many minutes is getting ready. */
    private const SOON_MINUTES = 60;

    /** @return array<string, mixed> */
    public function board(?string $date = null): array
    {
        $tz = config('app.timezone');
        $now = Carbon::now($tz);
        $day = $date ? Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay() : $now->copy()->startOfDay();
        $isToday = $day->isSameDay($now);

        $sessions = $this->sessionsBetween($day->copy()->startOfDay(), $day->copy()->endOfDay());
        $ctx = $this->context($sessions, $now);
        $briefs = $sessions->map(fn (ProgramSession $s) => $this->brief($s, $ctx, $now))->keyBy('id');

        // Floor by floor, and within a floor in the natural order of the room codes (R-2 before R-10).
        $rooms = TrainingRoom::where('status', 'active')->get()
            ->sort(fn (TrainingRoom $a, TrainingRoom $b) => [(string) $a->floor, 0] <=> [(string) $b->floor, 0] ?: strnatcasecmp((string) ($a->code ?? $a->name_ar), (string) ($b->code ?? $b->name_ar)))->values();
        $byRoom = $sessions->whereIn('training_room_id', $rooms->pluck('id'))->groupBy('training_room_id');

        $roomRows = $rooms->map(function (TrainingRoom $room) use ($byRoom, $briefs, $now, $isToday) {
            $mine = $byRoom->get($room->id, collect())->map(fn (ProgramSession $s) => $briefs[$s->id])->values();
            $live = $mine->firstWhere('state', 'live');
            $next = $mine->first(fn ($s) => in_array($s['state'], ['next', 'upcoming'], true));
            $last = $mine->last(fn ($s) => $s['state'] === 'ended');
            $state = match (true) {
                $live !== null => 'live',
                $next !== null => $isToday && $now->diffInMinutes(Carbon::parse($next['starts_at'])) <= self::SOON_MINUTES ? 'soon' : 'later',
                $last !== null => 'ended',
                default => 'idle',
            };

            return [
                'id' => $room->id, 'name' => $room->translate('name'), 'code' => $room->code, 'floor' => $room->floor, 'office' => $room->office, 'building' => $room->building,
                'capacity' => (int) $room->capacity, 'room_type' => $room->room_type ?? 'training',
                'state' => $state, 'focus_session_id' => ($live ?? $next ?? $last)['id'] ?? null,
                'sessions' => $mine->all(),
            ];
        })->values();

        $placed = $byRoom->flatten(1)->pluck('id')->flip();
        $unplaced = $sessions->reject(fn (ProgramSession $s) => $placed->has($s->id))->map(fn (ProgramSession $s) => $briefs[$s->id])->values();
        $started = $briefs->filter(fn ($s) => $s['state'] !== 'next' && $s['state'] !== 'upcoming');
        $liveNow = $briefs->where('state', 'live');

        return [
            'date' => $day->toDateString(), 'is_today' => $isToday, 'now' => $now->toIso8601String(), 'timezone' => $tz,
            'totals' => [
                'rooms' => $roomRows->count(), 'rooms_live' => $roomRows->where('state', 'live')->count(),
                'sessions' => $briefs->count(), 'sessions_live' => $liveNow->count(),
                'expected' => (int) $briefs->sum('counts.expected'), 'present' => (int) $briefs->sum('counts.present'), 'late' => (int) $briefs->sum('counts.late'),
                'in_rooms_now' => (int) $liveNow->sum(fn ($s) => count($s['present'])),
                'percent' => $this->percent((int) $started->sum('counts.present'), (int) $started->sum('counts.expected')),
            ],
            'rooms' => $roomRows->all(),
            'unplaced' => $unplaced->all(),
            'arrivals' => $this->arrivals($ctx, $sessions, $rooms),
            'strip' => $this->strip($day),
        ];
    }

    /** The training card of one classroom session: the programme, the people, the kit, the days and the roster. @return array<string, mixed> */
    public function card(ProgramSession $session): array
    {
        $tz = config('app.timezone');
        $now = Carbon::now($tz);
        $session->load(['program.category', 'program.units', 'room', 'trainer', 'trainingGroup.supervisor:id,name,name_ar,email', 'plcTrainers.trainer', 'program.coordinator:id,name,name_ar,email']);
        $sessions = collect([$session]);
        $ctx = $this->context($sessions, $now, withRoster: true);
        $brief = $this->brief($session, $ctx, $now);
        $program = $session->program;
        $group = $session->trainingGroup;
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        $siblings = ProgramSession::with('room:id,name_ar,name_en')->where('program_id', $program->id)->where('status', '!=', 'cancelled')
            ->when($this->narrowToGroup($session, $ctx), fn ($q) => $q->where('training_group_id', $session->training_group_id))
            ->orderBy('starts_at')->get();
        $number = $siblings->search(fn (ProgramSession $s) => $s->id === $session->id);

        $roster = $ctx['roster']->get($session->id, collect())->map(function (Registration $r) use ($ctx, $session, $brief) {
            $a = $ctx['records']->get($session->id, collect())->get($r->id);

            return [
                'id' => $r->id, 'name' => $r->employee?->user?->displayName() ?? '—', 'school' => $r->employee?->school?->translate('name'),
                'status' => $this->rosterStatus($a, $session, $brief['state']),
                'check_in_at' => $a?->check_in_at?->toIso8601String(), 'check_out_at' => $a?->check_out_at?->toIso8601String(), 'method' => $a?->method,
                'seat' => $ctx['seats']->get($session->id)['assignments'][$r->id] ?? null,
            ];
        });
        $order = ['present' => 0, 'late' => 1, 'left' => 2, 'excused' => 3, 'expected' => 4, 'absent' => 5];
        $roster = $roster->sort(fn ($a, $b) => [$order[$a['status']], $a['check_in_at'] ?? '', $a['name']] <=> [$order[$b['status']], $b['check_in_at'] ?? '', $b['name']])->values();

        $room = $session->room;

        return [
            'session' => [
                'id' => $session->id, 'title' => $session->translate('title'), 'number' => $number === false ? null : $number + 1, 'of' => $siblings->count(),
                'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(), 'minutes' => $session->durationMinutes(),
                'mode' => $session->mode, 'state' => $brief['state'], 'location' => $session->location_text, 'attendance_locked' => $session->attendance_locked_at !== null,
            ],
            'program' => [
                'id' => $program->id, 'code' => $program->code, 'title' => $program->translate('title'), 'summary' => $program->translate('summary'),
                'objectives' => array_values(array_filter((array) ($program->objectives ?? []), 'is_string')), 'category' => $program->category?->translate('name'),
                'type' => $program->effectiveType() ? ProgramTypes::name(ProgramTypes::normalize($program->effectiveType()), $locale) : null,
                'level' => $program->level, 'delivery_mode' => $program->delivery_mode, 'total_hours' => $program->total_hours !== null ? (float) $program->total_hours : null,
                'start_date' => $program->start_date?->toDateString(), 'end_date' => $program->end_date?->toDateString(),
                'units' => $program->units->map(fn ($u) => ['title' => $u->translate('title'), 'hours' => $u->hours !== null ? (float) $u->hours : null])->all(),
            ],
            'group' => $group ? [
                'id' => $group->id, 'title' => $group->{"title_{$locale}"} ?: $group->title_ar, 'code' => $group->code, 'capacity' => $group->capacity,
                'start_date' => $group->start_date?->toDateString(), 'end_date' => $group->end_date?->toDateString(), 'status' => $group->status,
            ] : null,
            'room' => $room ? [
                'id' => $room->id, 'name' => $room->translate('name'), 'code' => $room->code, 'floor' => $room->floor, 'office' => $room->office, 'building' => $room->building, 'capacity' => (int) $room->capacity,
                'equipment' => collect($room->facilities)->map(fn ($f) => ['key' => $f['key'], 'qty' => $f['qty'], 'label' => RoomCatalog::EQUIPMENT[$f['key']][$locale === 'en' ? 1 : 0] ?? $f['key']])->values()->all(),
            ] : null,
            'trainers' => $brief['trainers'],
            'supervisor' => $brief['supervisor'],
            'kit' => $brief['kit'],
            'counts' => $brief['counts'],
            'seating' => $brief['seating'],
            'schedule' => $siblings->map(fn (ProgramSession $s) => [
                'id' => $s->id, 'title' => $s->translate('title'), 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                'state' => $this->state($s, $now), 'room' => $s->room?->translate('name'), 'current' => $s->id === $session->id,
            ])->all(),
            'roster' => $roster->all(),
            'now' => $now->toIso8601String(),
        ];
    }

    /**
     * Dates are stored as the centre's own clock (the application timezone), so a day is read with local bounds.
     *
     * @return Collection<int, ProgramSession>
     */
    private function sessionsBetween(Carbon $from, Carbon $to): Collection
    {
        return ProgramSession::with([
            'program:id,code,title_ar,title_en,coordinator_id,program_type,category_id', 'trainer:id,name_ar,name_en,title_ar,title_en,photo_path,source,rating',
            'trainingGroup:id,program_id,title_ar,title_en,sequence,supervisor_id', 'plcTrainers.trainer:id,name_ar,name_en,title_ar,title_en,photo_path,source,rating',
        ])->where('status', '!=', 'cancelled')->whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->get();
    }

    /**
     * Everything the sessions share, read in a handful of queries instead of once per session.
     *
     * @param  Collection<int, ProgramSession>  $sessions
     * @return array<string, mixed>
     */
    private function context(Collection $sessions, Carbon $now, bool $withRoster = false): array
    {
        $ids = $sessions->pluck('id');
        $programIds = $sessions->pluck('program_id')->unique()->values();
        $groupIds = $sessions->pluck('training_group_id')->filter()->unique()->values();

        // A programme with several groups fills a room with one group only.
        $multi = TrainingGroup::whereIn('program_id', $programIds)->select('program_id', DB::raw('count(*) as n'))->groupBy('program_id')->pluck('n', 'program_id')->filter(fn ($n) => $n > 1);

        $registrations = Registration::whereIn('program_id', $programIds)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->when($withRoster, fn ($q) => $q->with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en']))
            ->get($withRoster ? ['*'] : ['id', 'program_id', 'training_group_id', 'employee_id']);
        $byProgram = $registrations->groupBy('program_id');
        $roster = $sessions->mapWithKeys(function (ProgramSession $s) use ($byProgram, $multi) {
            $all = $byProgram->get($s->program_id, collect());

            return [$s->id => $multi->has($s->program_id) && $s->training_group_id ? $all->where('training_group_id', $s->training_group_id)->values() : $all];
        });

        $records = Attendance::whereIn('program_session_id', $ids)->get()->groupBy('program_session_id')->map(fn ($rows) => $rows->keyBy('registration_id'));
        $checkedIn = $records->flatMap(fn ($rows) => $rows->filter(fn (Attendance $a) => $this->attended($a))->pluck('employee_id'))->unique();
        $names = $withRoster ? collect() : Employee::with('user:id,name,name_ar')->whereIn('id', $checkedIn)->get(['id', 'user_id'])->mapWithKeys(fn (Employee $e) => [$e->id => $e->user?->displayName() ?? '—']);

        // Seats: a plan made for the session wins over the one made for its group.
        $plans = SeatingPlan::whereIn('session_id', $ids)->orWhere(fn ($q) => $q->whereNull('session_id')->whereIn('group_id', $groupIds))->get();
        $seats = $sessions->mapWithKeys(function (ProgramSession $s) use ($plans) {
            $plan = $plans->first(fn (SeatingPlan $p) => $p->session_id === $s->id && $p->room_id === $s->training_room_id)
                ?? $plans->first(fn (SeatingPlan $p) => $p->session_id === null && $s->training_group_id && $p->group_id === $s->training_group_id && $p->room_id === $s->training_room_id);
            if (! $plan || empty($plan->layout['rows']) || empty($plan->layout['cols'])) {
                return [$s->id => null];
            }

            return [$s->id => [
                'layout' => ['rows' => (int) $plan->layout['rows'], 'cols' => (int) $plan->layout['cols'], 'blocked' => array_values($plan->layout['blocked'] ?? [])],
                'assignments' => collect($plan->assignments ?? [])->flip()->all(),   // registration id => "row,col"
            ]];
        });

        // Trainers: the session's own, the co-trainers of a workshop, else the trainers approved for the group.
        $groupTrainers = GroupTrainer::with('trainer:id,name_ar,name_en,title_ar,title_en,photo_path,source,rating')->whereIn('group_id', $groupIds)->where('status', GroupTrainer::APPROVED)->get()->groupBy('group_id');
        $trainerIds = $sessions->pluck('trainer_id')->merge($sessions->flatMap(fn (ProgramSession $s) => $s->plcTrainers->pluck('trainer_id')))->merge($groupTrainers->flatten(1)->pluck('trainer_id'))->filter()->unique()->values();
        $delivered = ProgramSession::whereIn('trainer_id', $trainerIds)->where('status', '!=', 'cancelled')->where('starts_at', '<=', $now)->distinct()->get(['trainer_id', 'program_id'])
            ->concat(PlcSessionTrainer::query()->join('program_sessions', 'program_sessions.id', '=', 'plc_session_trainers.program_session_id')->whereIn('plc_session_trainers.trainer_id', $trainerIds)
                ->where('program_sessions.status', '!=', 'cancelled')->where('program_sessions.starts_at', '<=', $now)->distinct()->get(['plc_session_trainers.trainer_id', 'program_sessions.program_id']))
            ->groupBy('trainer_id')->map(fn ($rows) => $rows->pluck('program_id')->unique()->count());
        $trainerIn = TrainerAttendance::whereIn('session_id', $ids)->whereNotNull('check_in_at')->get(['session_id', 'trainer_id'])->mapWithKeys(fn ($a) => ["{$a->session_id}|{$a->trainer_id}" => true]);

        $supervisorIds = $sessions->map(fn (ProgramSession $s) => $s->trainingGroup?->supervisor_id ?? $s->program?->coordinator_id)->filter()->unique();
        $supervisors = User::whereIn('id', $supervisorIds)->get(['id', 'name', 'name_ar', 'email'])->keyBy('id');

        $kits = TrainingKit::query()->join('kit_program', 'kit_program.kit_id', '=', 'training_kits.id')->whereIn('kit_program.program_id', $programIds)
            ->orderBy('kit_program.created_at')->get(['training_kits.id', 'training_kits.code', 'training_kits.title_ar', 'training_kits.title_en', 'training_kits.version', 'training_kits.status', 'kit_program.program_id as for_program', 'kit_program.pinned_version'])
            ->groupBy('for_program');
        $ownKits = TrainingKit::whereIn('program_id', $programIds->diff($kits->keys()))->whereNotIn('status', [TrainingKit::ARCHIVED])->orderByDesc('published_at')->get(['id', 'code', 'title_ar', 'title_en', 'version', 'status', 'program_id'])->groupBy('program_id');

        return compact('multi', 'roster', 'records', 'names', 'seats', 'groupTrainers', 'delivered', 'trainerIn', 'supervisors', 'kits', 'ownKits');
    }

    /**
     * One session as the board draws it.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function brief(ProgramSession $s, array $ctx, Carbon $now): array
    {
        $state = $this->state($s, $now);
        $expected = $ctx['roster']->get($s->id, collect());
        $expectedIds = $expected->pluck('id')->flip();
        $records = $ctx['records']->get($s->id, collect())->filter(fn (Attendance $a) => $expectedIds->has($a->registration_id));
        $attended = $records->filter(fn (Attendance $a) => $this->attended($a))->sortBy('check_in_at')->values();
        $left = $attended->filter(fn (Attendance $a) => $this->leftEarly($a, $s));
        $seat = $ctx['seats']->get($s->id);

        $present = $attended->reject(fn (Attendance $a) => $this->leftEarly($a, $s))->map(fn (Attendance $a) => [
            'id' => $a->registration_id,
            'name' => $ctx['names']->get($a->employee_id) ?? $expected->firstWhere('id', $a->registration_id)?->employee?->user?->displayName() ?? '—',
            'status' => $a->status === 'late' ? 'late' : 'present',
            'at' => $a->check_in_at?->toIso8601String(),
            'seat' => $seat['assignments'][$a->registration_id] ?? null,
        ])->values();

        $supervisorId = $s->trainingGroup?->supervisor_id ?? $s->program?->coordinator_id;
        $supervisor = $supervisorId ? $ctx['supervisors']->get($supervisorId) : null;
        $kit = $ctx['kits']->get($s->program_id)?->first() ?? $ctx['ownKits']->get($s->program_id)?->first();
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $group = $s->trainingGroup;

        return [
            'id' => $s->id, 'title' => $s->translate('title'), 'sequence' => $s->sequence, 'mode' => $s->mode ?? 'in_person', 'state' => $state,
            'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(), 'location' => $s->location_text,
            'program' => ['id' => $s->program_id, 'code' => $s->program?->code, 'title' => $s->program?->translate('title')],
            'group' => $group ? ['id' => $group->id, 'title' => $group->{"title_{$locale}"} ?: $group->title_ar] : null,
            'trainers' => $this->trainers($s, $ctx),
            'supervisor' => $supervisor ? ['id' => $supervisor->id, 'name' => $supervisor->displayName(), 'email' => $supervisor->email] : null,
            'kit' => $kit ? ['id' => $kit->id, 'code' => $kit->code, 'title' => $kit->translate('title'), 'version' => (int) ($kit->pinned_version ?? $kit->version), 'status' => $kit->status] : null,
            'counts' => [
                'expected' => $expected->count(), 'present' => $attended->count(), 'late' => $attended->where('status', 'late')->count(),
                'absent' => $state === 'ended' ? max(0, $expected->count() - $attended->count()) : 0, 'left' => $left->count(),
                'percent' => $this->percent($attended->count(), $expected->count()),
            ],
            'seating' => $seat['layout'] ?? null,
            'present' => $present->all(),
        ];
    }

    /** @param  array<string, mixed>  $ctx @return list<array<string, mixed>> */
    private function trainers(ProgramSession $s, array $ctx): array
    {
        $list = collect($s->trainer ? [['trainer' => $s->trainer, 'role' => 'lead']] : [])
            ->concat($s->plcTrainers->filter(fn ($p) => $p->trainer)->map(fn ($p) => ['trainer' => $p->trainer, 'role' => $p->role ?: 'co']))
            ->concat(($s->training_group_id ? $ctx['groupTrainers']->get($s->training_group_id, collect()) : collect())->filter(fn ($g) => $g->trainer)->map(fn ($g) => ['trainer' => $g->trainer, 'role' => $g->role ?: 'co']))
            ->unique(fn ($row) => $row['trainer']->id)->values();

        return $list->map(fn ($row) => $this->trainer($row['trainer'], $row['role'], $s, $ctx))->all();
    }

    /** @param  array<string, mixed>  $ctx @return array<string, mixed> */
    private function trainer(Trainer $t, string $role, ProgramSession $s, array $ctx): array
    {
        return [
            'id' => $t->id, 'name' => trim(($t->translate('title') ? $t->translate('title').' ' : '').$t->translate('name')), 'role' => $role,
            'photo' => FileStorage::publicUrl($t->photo_path), 'source' => $t->source ? $t->sourceLabel() : null, 'rating' => $t->rating !== null ? (float) $t->rating : null,
            'programs_count' => (int) ($ctx['delivered']->get($t->id) ?? 0),
            'checked_in' => $ctx['trainerIn']->has("{$s->id}|{$t->id}"),
        ];
    }

    private function narrowToGroup(ProgramSession $s, array $ctx): bool
    {
        return $ctx['multi']->has($s->program_id) && $s->training_group_id !== null;
    }

    private function state(ProgramSession $s, Carbon $now): string
    {
        return $s->ends_at->lte($now) ? 'ended' : ($s->starts_at->lte($now) ? 'live' : 'upcoming');
    }

    private function attended(Attendance $a): bool
    {
        return $a->check_in_at !== null && in_array($a->status, ['present', 'late'], true);
    }

    private function leftEarly(Attendance $a, ProgramSession $s): bool
    {
        return $a->left_early_at !== null || ($a->check_out_at !== null && $a->check_out_at->lt($s->ends_at->copy()->subMinutes(self::LEFT_EARLY_MINUTES)));
    }

    private function rosterStatus(?Attendance $a, ProgramSession $s, string $state): string
    {
        if ($a && $this->attended($a)) {
            return $this->leftEarly($a, $s) ? 'left' : ($a->status === 'late' ? 'late' : 'present');
        }
        if ($a?->status === 'excused') {
            return 'excused';
        }

        return $state === 'ended' ? 'absent' : 'expected';
    }

    private function percent(int $present, int $expected): int
    {
        return $expected > 0 ? (int) round($present / $expected * 100) : 0;
    }

    /**
     * The latest check-ins of the day, newest first: the ticker beside the drawing.
     *
     * @param  array<string, mixed>  $ctx
     * @param  Collection<int, ProgramSession>  $sessions
     * @param  Collection<int, TrainingRoom>  $rooms
     * @return list<array<string, mixed>>
     */
    private function arrivals(array $ctx, Collection $sessions, Collection $rooms): array
    {
        $bySession = $sessions->keyBy('id');
        $roomNames = $rooms->mapWithKeys(fn (TrainingRoom $r) => [$r->id => $r->translate('name')]);

        return $ctx['records']->flatMap(fn ($rows) => $rows->values())->filter(fn (Attendance $a) => $this->attended($a) && $bySession->has($a->program_session_id))
            ->sortByDesc('check_in_at')->take(12)->map(function (Attendance $a) use ($ctx, $bySession, $roomNames) {
                $s = $bySession[$a->program_session_id];

                return [
                    'id' => $a->id, 'name' => $ctx['names']->get($a->employee_id) ?? '—', 'status' => $a->status === 'late' ? 'late' : 'present', 'at' => $a->check_in_at->toIso8601String(),
                    'session_id' => $s->id, 'room_id' => $s->training_room_id, 'room' => $s->training_room_id ? $roomNames->get($s->training_room_id) : null, 'program' => $s->program?->translate('title'),
                ];
            })->values()->all();
    }

    /** Three days either side of the chosen day with how many sessions each holds, for the day strip. @return list<array{date: string, sessions: int}> */
    private function strip(Carbon $day): array
    {
        $tz = config('app.timezone');
        $from = $day->copy()->subDays(3)->startOfDay();
        $to = $day->copy()->addDays(3)->endOfDay();
        $counts = ProgramSession::where('status', '!=', 'cancelled')->whereBetween('starts_at', [$from, $to])->pluck('starts_at')
            ->countBy(fn ($at) => Carbon::parse($at)->timezone($tz)->toDateString());

        return collect(range(0, 6))->map(fn (int $i) => $from->copy()->addDays($i)->toDateString())->map(fn (string $d) => ['date' => $d, 'sessions' => (int) ($counts[$d] ?? 0)])->all();
    }
}
