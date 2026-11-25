<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\GroupTrainer;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * «برامج اليوم»: the sessions of today as somebody who runs the centre needs to read them from a phone — which programme,
 * in which room, who trains it, who supervises it, how many came — and the same sessions for a trainer, limited to
 * their own. The app's home screen shows the first few (AppActions::today); the board shows them all with a summary.
 *
 * Who sees what is unchanged: a trainer who does not manage programmes sees the sessions given to them; anybody else
 * needs `programs.view`; nobody else gets anything.
 */
class TodayBoard
{
    /** A session of a programme in these states is not part of the day. */
    private const HIDDEN = [Program::STATUS_CANCELLED, Program::STATUS_ARCHIVED];

    public function __construct(private readonly Wayfinding $wayfinding) {}

    /** Whether this person sees the whole centre's day (true), only their own sessions (false) or nothing (null). */
    public function reach(User $user): ?bool
    {
        if ($user->trainer && ! $user->hasPermission('programs.manage')) {
            return false;
        }

        return $user->hasPermission('programs.view') ? true : null;
    }

    /**
     * Today's sessions in the order they start.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(User $user, ?int $limit = null): array
    {
        $reach = $this->reach($user);
        if ($reach === null) {
            return [];
        }
        $sessions = ProgramSession::with(['program:id,code,title_ar,title_en,has_course,coordinator_id', 'program.coordinator:id,name,name_ar', 'program.trainers:id,name_ar,name_en,user_id', 'room', 'trainer:id,name_ar,name_en,user_id'])
            ->whereBetween('starts_at', [today(), today()->endOfDay()])->where('status', '!=', 'cancelled')
            ->whereHas('program', fn ($q) => $q->whereNotIn('status', self::HIDDEN))
            ->when($reach === false, fn ($q) => $q->where('trainer_id', $user->trainer->id))
            ->orderBy('starts_at')->when($limit, fn ($q) => $q->limit($limit))->get();
        if ($sessions->isEmpty()) {
            return [];
        }

        $programIds = $sessions->pluck('program_id')->unique();
        $groups = TrainingGroup::with('supervisor:id,name,name_ar')->whereIn('program_id', $programIds)->where('status', '!=', 'cancelled')->get();
        $groupTrainers = GroupTrainer::with('trainer:id,name_ar,name_en,user_id')->whereIn('group_id', $groups->pluck('id'))->where('status', GroupTrainer::APPROVED)->get()->groupBy('group_id');
        $present = Attendance::whereIn('program_session_id', $sessions->pluck('id'))->whereIn('status', ['present', 'late'])
            ->groupBy('program_session_id')->selectRaw('program_session_id as id, count(*) as n')->pluck('n', 'id');
        $expected = Registration::whereIn('program_id', $programIds)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->groupBy('program_id')->selectRaw('program_id as id, count(*) as n')->pluck('n', 'id');
        $now = now();

        return $sessions->map(function (ProgramSession $s) use ($groups, $groupTrainers, $present, $expected, $now) {
            $program = $s->program;
            // The groups this session belongs to: its own, or every group of the programme when it belongs to none.
            $own = $groups->where('program_id', $s->program_id)->when($s->training_group_id, fn (Collection $c) => $c->where('id', $s->training_group_id));
            $trainers = $s->trainer ? collect([$s->trainer]) : $own->flatMap(fn (TrainingGroup $g) => $groupTrainers->get($g->id, collect())->map->trainer)->filter()->merge($program?->trainers ?? [])->unique('id')->values();
            $supervisors = $own->map->supervisor->filter()->unique('id')->values();
            if ($supervisors->isEmpty() && $program?->coordinator) {
                $supervisors = collect([$program->coordinator]);
            }
            $room = $s->room?->translate('name') ?: $s->location_text;
            $when = $s->ends_at < $now ? 'past' : ($s->starts_at <= $now ? 'now' : 'later');

            return [
                // What the home card of earlier versions of the app reads.
                'id' => $s->id, 'title' => $s->translate('title'), 'program' => $program?->translate('title'), 'program_id' => $s->program_id, 'has_course' => (bool) $program?->has_course,
                'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                'location' => $s->location_text ?? $s->room?->translate('name'), 'room_id' => $s->training_room_id, 'map_place' => $this->wayfinding->placeOf($s->training_room_id, $s->location_text), 'mode' => $s->mode,
                // What the board adds.
                'program_code' => $program?->code, 'room' => $room,
                'trainers' => $trainers->map(fn (Trainer $t) => $t->translate('name'))->filter()->values()->all(),
                'supervisors' => $supervisors->map(fn (User $u) => $u->displayName())->filter()->values()->all(),
                'when' => $when,
                // A session still to be held with no trainer or no room is what somebody running the day wants to see first.
                'attention' => $when !== 'past' && ($trainers->isEmpty() || ! $room),
                'present' => (int) ($present[$s->id] ?? 0), 'expected' => (int) ($expected[$s->program_id] ?? 0),
            ];
        })->all();
    }

    /**
     * The day in a few numbers: how many sessions and how many are on now, and how many different programmes, rooms,
     * trainers and supervisors they involve.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(array $rows): array
    {
        $rows = collect($rows);
        $distinct = fn (string $key) => $rows->pluck($key)->flatten()->filter()->unique()->count();
        $live = $rows->where('when', 'now');

        return [
            'sessions' => $rows->count(), 'now' => $live->count(), 'later' => $rows->where('when', 'later')->count(), 'past' => $rows->where('when', 'past')->count(),
            'attention' => $rows->where('attention', true)->count(),
            'programs' => $distinct('program_id'), 'rooms' => $distinct('room'), 'trainers' => $distinct('trainers'), 'supervisors' => $distinct('supervisors'),
            // Of the sessions on now: who is in the rooms out of who is expected.
            'present' => (int) $live->sum('present'), 'expected' => (int) $live->sum('expected'),
        ];
    }
}
