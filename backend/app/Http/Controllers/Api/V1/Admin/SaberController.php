<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CalendarApproval;
use App\Models\CalendarDay;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\RoomService;
use App\Services\Saber\Workbench;
use App\Services\TrainingDaySettings;
use App\Services\TrainingGroupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * سابر+ — the programmes & groups workbench: every programme with its groups and each group's training days, edited in place
 * (docs/saber/00-gap-analysis.md, section A). Each action checks its own `saber.*` right.
 */
class SaberController extends Controller
{
    private const PAGE = 20;

    public function __construct(private readonly Workbench $bench, private readonly TrainingGroupService $groups, private readonly TrainingDaySettings $day) {}

    /** Header figures, the lists the editors pick from, the settings and what this person may do. */
    public function overview(): JsonResponse
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $user = $this->user();

        return response()->json(['data' => [
            'stats' => [
                'programs_month' => Program::whereHas('sessions', fn ($q) => $q->whereBetween('starts_at', [$monthStart, $monthEnd]))->count(),
                'awaiting_approval' => TrainingGroup::whereNull('validated_at')->where('status', '!=', TrainingGroup::CANCELLED)->whereHas('sessions')->count(),
                'booked_rooms' => ProgramSession::whereBetween('starts_at', [$monthStart, $monthEnd])->whereNotNull('training_room_id')->distinct()->count('training_room_id'),
                'trainers' => Trainer::where('status', 'active')->count(),
            ],
            'rooms' => TrainingRoom::where('room_type', 'training')->where('status', '!=', 'inactive')->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en', 'capacity', 'building', 'floor', 'tag'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name_ar ?: $r->name_en, 'capacity' => $r->capacity, 'building' => $r->building, 'floor' => $r->floor, 'tag' => $r->tag]),
            'trainers' => Trainer::where('status', 'active')->orderBy('name_ar')->get(['id', 'name_ar', 'name_en'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name_ar ?: $t->name_en]),
            'supervisors' => User::whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::COORDINATOR, Role::TRAINING_HEAD]))->where('status', 'active')->orderBy('name')
                ->get(['id', 'name', 'name_ar'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name_ar ?: $u->name]),
            'palette' => Workbench::PALETTE,
            'settings' => $this->settings(),
            'day' => ['hours' => $this->day->hours()] + $this->day->all(),
            'can' => collect(['saber.program.create', 'saber.program.cancel', 'saber.program.reactivate', 'saber.meeting.recurring', 'saber.group.manage', 'saber.group.rename', 'saber.group.validate',
                'saber.day.edit', 'saber.room.assign', 'saber.trainer.assign', 'saber.supervisor.assign', 'saber.plan.view', 'saber.import', 'saber.survey_links.manage',
                'trainers.manage', 'calendar.manage', 'calendar.approve', 'surveys.export', 'settings.manage'])->mapWithKeys(fn ($p) => [$p => $user->hasPermission($p)]),
        ]]);
    }

    /** The programmes, filtered (search, dates, term, kit), sorted by name, twenty a page, with their groups and days. */
    public function programs(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:120'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'term_first' => ['nullable', 'boolean'], 'term_second' => ['nullable', 'boolean'], 'kit_ready' => ['nullable', 'boolean'], 'kit_not_ready' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $showFirst = $f['term_first'] ?? true;
        $showSecond = $f['term_second'] ?? true;
        $showReady = $f['kit_ready'] ?? true;
        $showNotReady = $f['kit_not_ready'] ?? true;

        // A light pass over every programme decides which ones and which groups are shown; only the page is loaded in full.
        $firstDays = ProgramSession::query()->whereNotNull('training_group_id')
            ->select('training_group_id', DB::raw('min(starts_at) as first_at'), DB::raw('max(starts_at) as last_at'))->groupBy('training_group_id')->get()
            ->keyBy('training_group_id');
        $rows = Program::query()->with(['groups:id,program_id,term,status', 'kits:id,status'])
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('title_ar', "%{$t}%")->orWhereLike('title_en', "%{$t}%")->orWhereLike('code', "%{$t}%")))
            ->get(['id', 'title_ar', 'title_en', 'code', 'kit_ready', 'start_date', 'end_date']);
        $visible = [];
        foreach ($rows as $p) {
            $groups = $p->groups->filter(function ($g) use ($firstDays, $showFirst, $showSecond) {
                $first = $firstDays->get($g->id)?->first_at;
                $term = Workbench::termOf($g->term, $first ? CarbonImmutable::parse($first)->toDateString() : null);

                return match ($term) {
                    'first' => $showFirst, 'second' => $showSecond, default => $showFirst || $showSecond,
                };
            });
            if ($groups->isEmpty()) {
                continue;
            }
            $ready = $this->bench->kitReady($p);
            if (($ready && ! $showReady) || (! $ready && ! $showNotReady)) {
                continue;
            }
            $dates = $groups->flatMap(fn ($g) => array_filter([$firstDays->get($g->id)?->first_at, $firstDays->get($g->id)?->last_at]))->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->sort()->values();
            $start = $dates->first() ?? $p->start_date?->toDateString();
            $end = $dates->last() ?? $p->end_date?->toDateString();
            if (($f['from'] ?? null) && (! $end || $end < $f['from'])) {
                continue;
            }
            if (($f['to'] ?? null) && (! $start || $start > $f['to'])) {
                continue;
            }
            $visible[] = ['id' => $p->id, 'name' => $p->title_ar ?: $p->title_en, 'groups' => $groups->pluck('id')->all()];
        }
        usort($visible, fn ($a, $b) => strcmp($this->sortKey($a['name']), $this->sortKey($b['name'])));
        $total = count($visible);
        $page = min(max(1, (int) ($f['page'] ?? 1)), max(1, (int) ceil($total / self::PAGE)));
        $slice = array_slice($visible, ($page - 1) * self::PAGE, self::PAGE);
        $groupIds = array_merge(...array_map(fn ($v) => $v['groups'], $slice ?: [['groups' => []]]));

        $programs = Program::with(['coordinator:id,name,name_ar', 'kits:id,status', 'groups' => fn ($q) => $q->whereIn('id', $groupIds)->orderBy('sequence')])
            ->whereIn('id', array_column($slice, 'id'))->get()->keyBy('id');

        return response()->json([
            'data' => collect($slice)->map(fn ($v) => $this->programRow($programs[$v['id']]))->values(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => self::PAGE, 'last_page' => max(1, (int) ceil($total / self::PAGE))],
        ]);
    }

    /** For the day editors: is the date open for training, which rooms are taken and which trainers are busy in that time. */
    public function availability(Request $request, CalendarService $calendar, RoomService $rooms): JsonResponse
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'start_time' => ['nullable', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i'],
            'session_id' => ['nullable', 'uuid'],
        ]);
        $tz = config('app.timezone');
        $settings = $this->day->all();
        $start = CarbonImmutable::parse($d['date'].' '.($d['start_time'] ?? $settings['day_start']), $tz);
        $end = CarbonImmutable::parse($d['date'].' '.($d['end_time'] ?? $settings['day_end']), $tz);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addHour();
        }
        $except = $d['session_id'] ?? null;
        $busyRooms = [];
        foreach (TrainingRoom::where('room_type', 'training')->where('status', '!=', 'inactive')->pluck('id') as $id) {
            $holders = $rooms->occupants($id, $start, $end, $except);
            if ($holders !== []) {
                $busyRooms[$id] = $holders[0]['program'] ?? $holders[0]['title'];
            }
        }
        $busyTrainers = ProgramSession::whereNotNull('trainer_id')->where('status', '!=', 'cancelled')->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($except, fn ($q, $id) => $q->where('id', '!=', $id))->distinct()->pluck('trainer_id');

        return response()->json(['data' => [
            'day' => $calendar->describe($start, CalendarDay::whereDate('date', $d['date'])->first(), CalendarApproval::whereDate('date', $d['date'])->first()),
            'busy_rooms' => (object) $busyRooms, 'busy_trainers' => $busyTrainers->values(),
        ]]);
    }

    public function updateProgram(Request $request, Program $program): JsonResponse
    {
        $d = $request->validate([
            'kit_ready' => ['sometimes', 'boolean'], 'coordinator_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
            'training_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $this->need(array_key_exists('coordinator_id', $d) ? 'saber.supervisor.assign' : 'saber.group.manage');
        if (array_key_exists('coordinator_id', $d)) {
            abort_unless($this->settings()['supervisor_edit_enabled'], 403, __('messages.saber.supervisor_locked'));
        }
        $program->update($d);

        return response()->json(['data' => $this->programRow($program->fresh(['coordinator:id,name,name_ar', 'kits:id,status', 'groups' => fn ($q) => $q->orderBy('sequence')]))]);
    }

    public function addGroup(Program $program): JsonResponse
    {
        $this->need('saber.group.manage');
        $colour = $this->bench->nextColour($program);
        $group = $this->groups->create($program, [], $this->user())['group'];
        $group->update(['color' => $colour]);

        return response()->json(['data' => $this->groupRow($group->fresh(), $this->bench->daysPerGroup($program))], 201);
    }

    public function updateGroup(Request $request, TrainingGroup $group): JsonResponse
    {
        $d = $request->validate([
            'title_ar' => ['sometimes', 'nullable', 'string', 'max:160'], 'sequence' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'term' => ['sometimes', 'nullable', Rule::in(['first', 'second', 'year'])],
        ]);
        if (array_key_exists('title_ar', $d)) {
            abort_unless($this->user()->hasPermission('saber.group.rename') || $this->user()->hasPermission('saber.group.manage'), 403);
            $taken = TrainingGroup::where('program_id', $group->program_id)->where('id', '!=', $group->id)->where('title_ar', $d['title_ar'])->exists();
            abort_if($d['title_ar'] && $taken, 422, __('messages.groups.duplicate_title', ['title' => $d['title_ar']]));
            $d['title_ar'] = $d['title_ar'] ?: TrainingGroup::defaultTitle($group->sequence, 'ar');
        }
        if (array_key_exists('sequence', $d)) {
            $this->need('saber.group.manage');
            abort_if(TrainingGroup::where('program_id', $group->program_id)->where('id', '!=', $group->id)->where('sequence', $d['sequence'])->exists(), 422, __('messages.saber.number_taken', ['n' => $d['sequence']]));
        }
        if (array_key_exists('color', $d) || array_key_exists('term', $d)) {
            abort_unless($this->user()->hasPermission('saber.group.manage') || $this->user()->hasPermission('saber.group.rename'), 403);
        }
        $group->update($d);

        return response()->json(['data' => $this->groupRow($group->fresh(), $this->bench->daysPerGroup($group->program))]);
    }

    public function deleteGroup(TrainingGroup $group): JsonResponse
    {
        $this->need('saber.group.manage');
        abort_unless($this->settings()['group_delete_enabled'], 403, __('messages.saber.delete_disabled'));
        $this->groups->delete($group);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** «اعتماد المجموعة»: press again to withdraw it. */
    public function validateGroup(TrainingGroup $group): JsonResponse
    {
        $this->need('saber.group.validate');
        $user = $this->user();
        $group->update($group->validated_at
            ? ['validated_at' => null, 'validated_by' => null, 'validated_by_name' => null]
            : ['validated_at' => now(), 'validated_by' => $user->id, 'validated_by_name' => $user->name_ar ?: $user->name]);

        return response()->json(['data' => $this->groupRow($group->fresh(), $this->bench->daysPerGroup($group->program))]);
    }

    public function setDay(Request $request, TrainingGroup $group, int $idx): JsonResponse
    {
        $d = $request->validate([
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'start_time' => ['sometimes', 'date_format:H:i'], 'end_time' => ['sometimes', 'date_format:H:i'],
            'room_id' => ['sometimes', 'nullable', 'uuid', 'exists:training_rooms,id'], 'trainer_id' => ['sometimes', 'nullable', 'uuid', 'exists:trainers,id'],
        ]);
        abort_if($idx < 1 || $idx > 200, 404);
        abort_if($group->status === TrainingGroup::CANCELLED, 422, __('messages.saber.group_cancelled'));
        if (array_key_exists('date', $d) || array_key_exists('start_time', $d) || array_key_exists('end_time', $d)) {
            $this->need('saber.day.edit');
        }
        if (array_key_exists('room_id', $d)) {
            $this->need('saber.room.assign');
        }
        if (array_key_exists('trainer_id', $d)) {
            $this->need('saber.trainer.assign');
        }
        $notices = $this->bench->setDay($group, $idx, $d, $this->user());

        return response()->json(['data' => $this->groupRow($group->fresh(), $this->bench->daysPerGroup($group->program)), 'notices' => $notices]);
    }

    public function resetDay(TrainingGroup $group, int $idx): JsonResponse
    {
        $this->need('saber.day.edit');
        $this->bench->resetDay($group, $idx);

        return response()->json(['data' => $this->groupRow($group->fresh(), $this->bench->daysPerGroup($group->program))]);
    }

    public function resetGroup(TrainingGroup $group): JsonResponse
    {
        $this->need('saber.day.edit');
        $n = $this->bench->resetGroup($group);

        return response()->json(['data' => $this->groupRow($group->fresh(), $this->bench->daysPerGroup($group->program)), 'reset' => $n]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['supervisor_edit_enabled' => ['sometimes', 'boolean'], 'group_delete_enabled' => ['sometimes', 'boolean']]);
        SiteSetting::updateOrCreate(['key' => 'saber'], ['value' => array_merge($this->settings(), $d), 'updated_by' => $this->user()->id]);

        return response()->json(['data' => $this->settings()]);
    }

    /** @return array{supervisor_edit_enabled: bool, group_delete_enabled: bool} */
    private function settings(): array
    {
        $v = (array) (SiteSetting::find('saber')?->value ?? []);

        return ['supervisor_edit_enabled' => (bool) ($v['supervisor_edit_enabled'] ?? true), 'group_delete_enabled' => (bool) ($v['group_delete_enabled'] ?? false)];
    }

    /** @return array<string, mixed> */
    private function programRow(Program $p): array
    {
        $days = $this->bench->daysPerGroup($p);
        $groups = $p->groups->map(fn ($g) => $this->groupRow($g, $days))->values();
        $all = $groups->flatMap(fn ($g) => collect($g['days'])->where('extra', false))->values();
        $dates = $all->pluck('date')->filter()->sort()->values();

        return [
            'id' => $p->id, 'code' => $p->code, 'title' => $p->title_ar ?: $p->title_en, 'title_en' => $p->title_en, 'type' => $p->program_type, 'method' => $p->delivery_mode,
            'hours' => (float) $p->total_hours, 'days' => $days, 'status' => $p->status, 'cancelled' => $p->status === Program::STATUS_CANCELLED,
            'kit_ready' => $this->bench->kitReady($p), 'kit_ready_set' => $p->kit_ready !== null, 'survey_link' => $p->survey_link, 'survey_edit_link' => $p->survey_edit_link,
            'supervisor' => $p->coordinator ? ['id' => $p->coordinator->id, 'name' => $p->coordinator->name_ar ?: $p->coordinator->name] : null,
            'start_date' => $dates->first() ?? $p->start_date?->toDateString(), 'end_date' => $dates->last() ?? $p->end_date?->toDateString(),
            'lights' => ['total' => $groups->count() * $days, 'dates' => $all->whereNotNull('date')->count(), 'rooms' => $all->whereNotNull('room_id')->count(), 'trainers' => $all->whereNotNull('trainer_id')->count()],
            'groups' => $groups,
        ];
    }

    /** @return array<string, mixed> */
    private function groupRow(TrainingGroup $g, int $days): array
    {
        $list = $this->bench->days($g, $days);
        $first = collect($list)->pluck('date')->filter()->sort()->first();

        return [
            'id' => $g->id, 'program_id' => $g->program_id, 'number' => $g->sequence, 'title' => $g->title_ar, 'color' => $g->color,
            'term' => $g->term, 'term_effective' => Workbench::termOf($g->term, $first), 'status' => $g->status, 'cancelled' => $g->status === TrainingGroup::CANCELLED,
            'validated_at' => $g->validated_at?->toIso8601String(), 'validated_by_name' => $g->validated_by_name, 'days' => $list,
        ];
    }

    /** Arabic-aware sort key: letters without hamza variants and diacritics. */
    private function sortKey(string $s): string
    {
        return strtr(preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $s) ?? $s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }

    private function need(string $permission): void
    {
        abort_unless($this->user()->hasPermission($permission), 403);
    }
}
