<?php

namespace App\Services\Duties;

use App\Models\Attendance;
use App\Models\GroupTrainer;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\Saber\FormLinks;
use Illuminate\Support\Collection;

/**
 * «برامجي»: the programme groups a person was given to work in — as the **supervisor** of the group, as one of its
 * **trainers**, or both — whichever screen the assignment was made on. «البرامج» and «سابر+» write the same records
 * (the group's supervisor, its approved trainers, the trainer of a session, the trainers of the programme), so one
 * reading covers both.
 *
 * It answers two things: what to show the person (rows), and whether a session is theirs to run (supervises / teaches),
 * which is what lets a group's own supervisor open its attendance and its QR code. Being given a programme never opens
 * another programme: every check is against the person's own assignments.
 */
class MyDuties
{
    /** A programme in these states is not announced to the people assigned to it. */
    private const HIDDEN = [Program::STATUS_DRAFT, Program::STATUS_CANCELLED, Program::STATUS_ARCHIVED];

    public function __construct(private readonly FormLinks $forms) {}

    /** @return Collection<int, string> the groups this person supervises */
    public function supervisedGroupIds(User $user): Collection
    {
        return TrainingGroup::where('supervisor_id', $user->id)->where('status', '!=', 'cancelled')->pluck('id');
    }

    /**
     * The groups this person teaches: an approved assignment to the group, a session of the group given to them, or
     * (the older way) being a trainer of the whole programme, which covers every group of it.
     *
     * @return Collection<int, string>
     */
    public function taughtGroupIds(User $user): Collection
    {
        $trainer = Trainer::where('user_id', $user->id)->first();
        if (! $trainer) {
            return collect();
        }
        $byGroup = GroupTrainer::where('trainer_id', $trainer->id)->where('status', GroupTrainer::APPROVED)->pluck('group_id');
        $sessions = ProgramSession::where('trainer_id', $trainer->id)->where('status', '!=', 'cancelled')->get(['program_id', 'training_group_id']);
        // A session that belongs to no group is a session of every group of its programme.
        $wholeProgrammes = $trainer->programs()->pluck('programs.id')->merge($sessions->whereNull('training_group_id')->pluck('program_id'))->unique();

        return $byGroup
            ->merge($sessions->pluck('training_group_id')->filter())
            ->merge(TrainingGroup::whereIn('program_id', $wholeProgrammes)->pluck('id'))
            ->unique()->values();
    }

    /** Whether the person is the supervisor of the group this session belongs to (or of a group of its programme, when it belongs to none). */
    public function supervises(User $user, ProgramSession $session): bool
    {
        return TrainingGroup::where('supervisor_id', $user->id)->where('program_id', $session->program_id)->where('status', '!=', 'cancelled')
            ->when($session->training_group_id, fn ($q, $group) => $q->whereKey($group))->exists();
    }

    /** Whether the person teaches this session: it is theirs, or they teach its group or its programme. */
    public function teaches(User $user, ProgramSession $session): bool
    {
        $trainer = Trainer::where('user_id', $user->id)->first();
        if (! $trainer) {
            return false;
        }
        if ($session->trainer_id === $trainer->id || $session->program->trainers()->where('trainers.id', $trainer->id)->exists()) {
            return true;
        }

        return GroupTrainer::where('trainer_id', $trainer->id)->where('status', GroupTrainer::APPROVED)
            ->whereIn('group_id', TrainingGroup::where('program_id', $session->program_id)->when($session->training_group_id, fn ($q, $group) => $q->whereKey($group))->pluck('id'))->exists();
    }

    /**
     * One row per group, the one in progress first, then the next to start, then the finished ones (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(User $user): array
    {
        $supervised = $this->supervisedGroupIds($user);
        $taught = $this->taughtGroupIds($user);
        $groups = TrainingGroup::with(['program.coordinator', 'program.trainers.user', 'supervisor', 'trainers.trainer.user'])
            ->whereIn('id', $supervised->merge($taught)->unique())->where('status', '!=', 'cancelled')
            ->whereHas('program', fn ($q) => $q->whereNotIn('status', self::HIDDEN))->get();
        if ($groups->isEmpty()) {
            return [];
        }

        $programs = $groups->pluck('program')->unique('id')->values();
        $programIds = $programs->pluck('id');
        $sessions = ProgramSession::with('room')->whereIn('program_id', $programIds)->where('status', '!=', 'cancelled')->orderBy('starts_at')->get()->groupBy('program_id');
        $present = Attendance::whereIn('program_session_id', $sessions->flatten()->pluck('id'))->whereIn('status', ['present', 'late'])
            ->groupBy('program_session_id')->selectRaw('program_session_id as id, count(*) as n')->pluck('n', 'id');
        $registered = Registration::whereIn('program_id', $programIds)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get(['program_id', 'training_group_id']);
        $groupsOf = TrainingGroup::whereIn('program_id', $programIds)->where('status', '!=', 'cancelled')->get(['id', 'program_id'])->groupBy('program_id');
        $forms = $this->forms->many($programs);
        $trainerId = Trainer::where('user_id', $user->id)->value('id');
        $now = now();

        $rows = $groups->map(function (TrainingGroup $group) use ($supervised, $taught, $sessions, $present, $registered, $groupsOf, $forms, $trainerId, $now) {
            $program = $group->program;
            $single = $groupsOf->get($program->id, collect())->count() <= 1;
            $own = $sessions->get($program->id, collect())->filter(fn (ProgramSession $s) => $s->training_group_id === null || $s->training_group_id === $group->id)->values();
            // A trainee placed in a group counts for that group; one placed in none counts for the programme's only group.
            $inProgramme = $registered->where('program_id', $program->id);
            $trainees = $single ? $inProgramme->count() : $inProgramme->where('training_group_id', $group->id)->count();
            // The attendance sheet of a session lists the programme's trainees (AttendanceService::sessionReport), so that is what a session expects.
            $expected = $inProgramme->count();

            $from = $own->first()?->starts_at ?? $group->start_date ?? $program->start_date;
            $to = $own->last()?->ends_at ?? $group->end_date ?? $program->end_date;
            $phase = $to && $to->copy()->endOfDay()->lt($now) ? 'ended' : ($from && $from->gt($now) && ! $from->isToday() ? 'upcoming' : 'running');
            // The session to act on: the one happening now, else the next one today or later, else the last one held.
            $focus = $own->first(fn (ProgramSession $s) => $s->starts_at <= $now && $s->ends_at >= $now) ?? $own->first(fn (ProgramSession $s) => $s->ends_at >= $now) ?? $own->last();

            $trainers = $group->trainers->where('status', GroupTrainer::APPROVED)->map->trainer->filter()
                ->merge($program->trainers)->merge($own->pluck('trainer_id')->filter()->unique()->map(fn ($id) => $program->trainers->firstWhere('id', $id))->filter())
                ->unique('id')->values();
            $supervisor = $group->supervisor ?? $program->coordinator;
            $rooms = $own->map(fn (ProgramSession $s) => $this->place($s))->filter()->unique(fn ($r) => $r['ar'])->values()->all();

            return [
                'id' => $group->id,
                'capacities' => array_values(array_filter([$supervised->contains($group->id) ? 'supervisor' : null, $taught->contains($group->id) ? 'trainer' : null])),
                'phase' => $phase,
                'program' => ['id' => $program->id, 'code' => $program->code, 'title_ar' => $program->title_ar, 'title_en' => $program->title_en ?: $program->title_ar, 'status' => $program->status, 'delivery_mode' => $program->delivery_mode],
                'group' => ['id' => $group->id, 'title_ar' => $group->displayTitle('ar'), 'title_en' => $group->displayTitle('en'), 'status' => $group->status, 'only' => $single],
                'from' => $from?->toIso8601String(), 'to' => $to?->toIso8601String(),
                'supervisor' => $supervisor ? ['ar' => $supervisor->displayName('ar'), 'en' => $supervisor->displayName('en'), 'me' => $supervisor->id === $group->supervisor_id && $supervised->contains($group->id)] : null,
                'trainers' => $trainers->map(fn (Trainer $t) => ['ar' => $t->user?->displayName('ar') ?? $t->name_ar ?? $t->name_en, 'en' => $t->user?->displayName('en') ?? $t->name_en ?? $t->name_ar, 'me' => $t->id === $trainerId])->values()->all(),
                'rooms' => $rooms,
                'trainees' => $trainees,
                'sessions' => $own->map(fn (ProgramSession $s) => $this->sessionRow($s, (int) ($present[$s->id] ?? 0), $expected, $now, $focus?->id === $s->id))->all(),
                'forms' => $forms[$program->id] ?? null,
            ];
        });

        $order = ['running' => 0, 'upcoming' => 1, 'ended' => 2];

        return $rows->sort(function (array $a, array $b) use ($order) {
            if ($a['phase'] !== $b['phase']) {
                return $order[$a['phase']] <=> $order[$b['phase']];
            }

            // Finished groups newest first; the others soonest first.
            return $a['phase'] === 'ended' ? strcmp((string) $b['to'], (string) $a['to']) : strcmp((string) $a['from'], (string) $b['from']);
        })->values()->all();
    }

    /**
     * The trainees of a group, each with how many of the group's sessions held so far they attended: the list the people
     * who run the group work from. A trainee placed in no group belongs to the programme's only group, as in rows().
     *
     * @return array{held: int, trainees: list<array<string, mixed>>}
     */
    public function trainees(TrainingGroup $group): array
    {
        $single = TrainingGroup::where('program_id', $group->program_id)->where('status', '!=', 'cancelled')->count() <= 1;
        $registrations = Registration::with('employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en')
            ->where('program_id', $group->program_id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->when(! $single, fn ($q) => $q->where('training_group_id', $group->id))->get();
        $held = ProgramSession::where('program_id', $group->program_id)->where('status', '!=', 'cancelled')->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('training_group_id')->orWhere('training_group_id', $group->id))->pluck('id');
        $attended = Attendance::whereIn('program_session_id', $held)->whereIn('registration_id', $registrations->pluck('id'))->whereIn('status', ['present', 'late'])
            ->groupBy('registration_id')->selectRaw('registration_id as id, count(*) as n')->pluck('n', 'id');

        return [
            'held' => $held->count(),
            'trainees' => $registrations->map(fn (Registration $r) => [
                'registration_id' => $r->id,
                'name' => (string) ($r->employee?->user?->displayName() ?? ''),
                'employee_no' => $r->employee?->employee_no,
                'school' => $r->employee?->school?->translate('name'),
                'attended' => (int) ($attended[$r->id] ?? 0),
            ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionRow(ProgramSession $s, int $present, int $expected, \DateTimeInterface $now, bool $focus): array
    {
        $when = $s->ends_at < $now ? 'past' : ($s->starts_at <= $now ? 'now' : ($s->starts_at->isToday() ? 'today' : 'later'));

        return [
            'id' => $s->id, 'sequence' => $s->sequence, 'title_ar' => $s->title_ar, 'title_en' => $s->title_en ?: $s->title_ar,
            'starts_at' => $s->starts_at?->toIso8601String(), 'ends_at' => $s->ends_at?->toIso8601String(), 'status' => $s->status, 'mode' => $s->mode,
            'when' => $when, 'focus' => $focus, 'place' => $this->place($s),
            'present' => $present, 'expected' => $expected,
        ];
    }

    /** Where a session is held: its room, else the place written for it. @return array{ar: string, en: string}|null */
    private function place(ProgramSession $s): ?array
    {
        if ($s->room) {
            return ['ar' => (string) ($s->room->name_ar ?: $s->room->name_en), 'en' => (string) ($s->room->name_en ?: $s->room->name_ar)];
        }

        return $s->location_text ? ['ar' => (string) $s->location_text, 'en' => (string) $s->location_text] : null;
    }
}
