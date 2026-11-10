<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CalendarApproval;
use App\Models\CalendarDay;
use App\Models\CalendarRequest;
use App\Models\CalendarWeeklyRule;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\RoomBooking;
use App\Models\SaberRevision;
use App\Models\SiteSetting;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\FileStorage;
use App\Services\ProgramCatalogSettings;
use App\Services\Saber\Drafts;
use App\Services\Saber\FormLinks;
use App\Services\Saber\Meetings;
use App\Services\Saber\Scheduling;
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

    public function __construct(private readonly Workbench $bench, private readonly TrainingGroupService $groups, private readonly TrainingDaySettings $day, private readonly Drafts $drafts) {}

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
            // A new trainer waits for approval before supervisors can pick them; whoever approves trainers sees them, marked.
            'trainers' => Trainer::where('status', 'active')->when(! $user->hasPermission('trainers.validate'), fn ($q) => $q->whereNotNull('validated_at'))->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en', 'validated_at'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name_ar ?: $t->name_en, 'pending' => ! $t->validated_at]),
            'supervisors' => User::whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::COORDINATOR, Role::TRAINING_HEAD]))->where('status', 'active')->orderBy('name')
                ->get(['id', 'name', 'name_ar'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name_ar ?: $u->name]),
            'palette' => Workbench::PALETTE,
            'me' => $user->id,
            'drafts' => ['mine' => SaberRevision::whereIn('status', SaberRevision::OPEN)->where('created_by', $user->id)->count(), 'submitted' => SaberRevision::where('status', SaberRevision::SUBMITTED)->count()],
            'offday_pending' => CalendarRequest::where('status', CalendarRequest::PENDING)->when(! $user->hasPermission('calendar.approve'), fn ($q) => $q->where('requested_by', $user->id))->count(),
            'settings' => $this->settings(),
            'day' => ['hours' => $this->day->hours()] + $this->day->all(),
            'can' => collect(['saber.program.create', 'saber.program.cancel', 'saber.program.reactivate', 'saber.meeting.recurring', 'saber.group.manage', 'saber.group.rename', 'saber.group.validate',
                'saber.day.edit', 'saber.room.assign', 'saber.trainer.assign', 'saber.supervisor.assign', 'saber.plan.view', 'saber.import', 'saber.survey_links.manage',
                'trainers.manage', 'calendar.manage', 'calendar.approve', 'surveys.export', 'settings.manage', 'rooms.book', 'rooms.manage', 'programs.manage', 'saber.publish'])->mapWithKeys(fn ($p) => [$p => $user->hasPermission($p)]),
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
        // A programme with an open draft is filtered by its planned groups and dates.
        $openDrafts = SaberRevision::whereIn('status', SaberRevision::OPEN)->get(['program_id', 'state'])->keyBy('program_id');
        foreach ($openDrafts as $programId => $rev) {
            foreach ($rev->state['groups'] ?? [] as $g) {
                $dates = collect($g['days'] ?? [])->pluck('date')->filter()->sort()->values();
                $firstDays->put($g['key'], (object) ['first_at' => $dates->first(), 'last_at' => $dates->last()]);
            }
        }
        $visible = [];
        foreach ($rows as $p) {
            $planned = isset($openDrafts[$p->id])
                ? collect($openDrafts[$p->id]->state['groups'] ?? [])->reject(fn ($g) => ! empty($g['deleted']))->map(fn ($g) => (object) ['id' => $g['key'], 'term' => $g['term'] ?? null, 'status' => $g['status'] ?? null])
                : $p->groups;
            $groups = $planned->filter(function ($g) use ($firstDays, $showFirst, $showSecond) {
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
            'data' => collect($slice)->map(fn ($v) => $this->rowOf($programs[$v['id']]))->values(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => self::PAGE, 'last_page' => max(1, (int) ceil($total / self::PAGE))],
        ]);
    }

    /** For the day editors: is the date open, which rooms are taken and which trainers are busy — live bookings of other programmes and the open drafts. */
    public function availability(Request $request, CalendarService $calendar): JsonResponse
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'start_time' => ['nullable', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i'],
            'program_id' => ['required', 'uuid', 'exists:programs,id'], 'group' => ['required', 'uuid'], 'idx' => ['required', 'integer', 'min:1', 'max:200'],
        ]);
        $tz = config('app.timezone');
        $settings = $this->day->all();
        $start = CarbonImmutable::parse($d['date'].' '.($d['start_time'] ?? $settings['day_start']), $tz);
        $end = CarbonImmutable::parse($d['date'].' '.($d['end_time'] ?? $settings['day_end']), $tz);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addHour();
        }
        $busy = $this->drafts->availability(Program::find($d['program_id']), $d['group'], (int) $d['idx'], $start, $end);

        return response()->json(['data' => [
            'day' => $calendar->describe($start, CalendarDay::whereDate('date', $d['date'])->first(), CalendarApproval::whereDate('date', $d['date'])->first()),
            'busy_rooms' => (object) $busy['rooms'], 'busy_trainers' => array_keys($busy['trainers']), 'trainer_holders' => (object) $busy['trainers'],
        ]]);
    }

    /** Programme fields: kit readiness changes the live programme; the supervisor and the days per group are planned (draft). */
    public function updateProgram(Request $request, Program $program): JsonResponse
    {
        $d = $request->validate([
            'kit_ready' => ['sometimes', 'boolean'], 'coordinator_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
            'training_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $this->need(match (true) {
            array_key_exists('coordinator_id', $d) => 'saber.supervisor.assign',
            default => 'saber.group.manage',
        });
        if (array_key_exists('coordinator_id', $d)) {
            abort_unless($this->settings()['supervisor_edit_enabled'], 403, __('messages.saber.supervisor_locked'));
        }
        $planned = array_intersect_key($d, array_flip(['coordinator_id', 'training_days']));
        if ($planned !== []) {
            $this->drafts->setProgram($this->drafts->draft($program, $this->user()), $planned, $this->user());
        }
        $live = array_diff_key($d, $planned);
        if ($live !== []) {
            $program->update($live);
        }

        return response()->json(['data' => $this->rowOf($program->fresh())]);
    }

    /** «برنامج جديد» from SABER+: created as a draft programme, published by the reviewer. */
    public function createProgram(Request $request, ProgramCatalogSettings $catalog): JsonResponse
    {
        $this->need('saber.program.create');
        $d = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'delivery_mode' => ['required', Rule::in(['in_person', 'online', 'hybrid'])], 'total_hours' => ['required', 'numeric', 'min:1', 'max:2000'],
            'training_days' => ['nullable', 'integer', 'min:1', 'max:200'], 'groups' => ['required', 'integer', 'min:1', 'max:30'], 'kit_ready' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date_format:Y-m-d'], 'coordinator_id' => ['nullable', 'uuid', 'exists:users,id'], 'objective' => ['nullable', 'string', 'max:2000'], 'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        abort_if(Program::where('title_ar', $d['title'])->exists(), 422, __('messages.saber.title_taken'));
        $user = $this->user();
        $program = DB::transaction(function () use ($d, $catalog, $user) {
            $program = Program::create([
                'code' => $catalog->nextCode(null), 'title_ar' => $d['title'], 'title_en' => $d['title'], 'status' => Program::STATUS_DRAFT, 'delivery_mode' => $d['delivery_mode'],
                'total_hours' => $d['total_hours'], 'training_days' => $d['training_days'] ?? null, 'kit_ready' => $d['kit_ready'] ?? null, 'start_date' => $d['start_date'] ?? null,
                'coordinator_id' => $d['coordinator_id'] ?? null, 'objectives' => ! empty($d['objective']) ? [$d['objective']] : null, 'capacity' => $d['capacity'] ?? 25,
                'min_attendance_percent' => 80, 'registration_modes' => Program::MODES, 'created_by' => $user->id,
            ]);
            $rev = $this->drafts->draft($program, $user, 'new_program');
            for ($i = 1; $i < $d['groups']; $i++) {
                $this->drafts->addGroup($rev->fresh(), $user, ['supervisor_id' => $d['coordinator_id'] ?? null]);
            }

            return $program;
        });

        return response()->json(['data' => $this->rowOf($program->fresh())], 201);
    }

    public function addGroup(Program $program): JsonResponse
    {
        $this->need('saber.group.manage');
        $rev = $this->drafts->draft($program, $this->user());
        $group = $this->drafts->addGroup($rev, $this->user(), ['supervisor_id' => $this->drafts->field($program, $rev->state, 'coordinator_id')]);

        return response()->json(['data' => $this->groupOf($program, $rev->fresh(), $group['key'])], 201);
    }

    public function updateGroup(Request $request, Program $program, string $key): JsonResponse
    {
        $d = $request->validate([
            'title_ar' => ['sometimes', 'nullable', 'string', 'max:160'], 'sequence' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'term' => ['sometimes', 'nullable', Rule::in(['first', 'second', 'year'])],
            'supervisor_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
        ]);
        if (array_key_exists('title_ar', $d) || array_key_exists('color', $d) || array_key_exists('term', $d)) {
            abort_unless($this->user()->hasPermission('saber.group.rename') || $this->user()->hasPermission('saber.group.manage'), 403);
        }
        if (array_key_exists('sequence', $d)) {
            $this->need('saber.group.manage');
        }
        if (array_key_exists('supervisor_id', $d)) {
            $this->need('saber.supervisor.assign');
        }
        $patch = [];
        foreach (['title_ar' => 'title', 'sequence' => 'number', 'color' => 'color', 'term' => 'term', 'supervisor_id' => 'supervisor_id'] as $in => $out) {
            if (array_key_exists($in, $d)) {
                $patch[$out] = $in === 'sequence' ? (int) $d[$in] : $d[$in];
            }
        }
        $rev = $this->drafts->draft($program, $this->user());
        $this->drafts->updateGroup($rev, $key, $patch, $this->user());

        return response()->json(['data' => $this->groupOf($program, $rev->fresh(), $key)]);
    }

    public function deleteGroup(Program $program, string $key): JsonResponse
    {
        $this->need('saber.group.manage');
        abort_unless($this->settings()['group_delete_enabled'], 403, __('messages.saber.delete_disabled'));
        $this->drafts->deleteGroup($this->drafts->draft($program, $this->user()), $key, $this->user());

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** «اعتماد المجموعة» is the head of training's sign-off on a live group: press again to withdraw it. */
    public function validateGroup(TrainingGroup $group): JsonResponse
    {
        $this->need('saber.group.validate');
        $user = $this->user();
        $group->update($group->validated_at
            ? ['validated_at' => null, 'validated_by' => null, 'validated_by_name' => null]
            : ['validated_at' => now(), 'validated_by' => $user->id, 'validated_by_name' => $user->name_ar ?: $user->name]);

        return response()->json(['data' => $this->groupOf($group->program, $this->drafts->open($group->program), $group->id)]);
    }

    public function setDay(Request $request, Program $program, string $key, int $idx): JsonResponse
    {
        $d = $request->validate([
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'start_time' => ['sometimes', 'date_format:H:i'], 'end_time' => ['sometimes', 'date_format:H:i'],
            'room_id' => ['sometimes', 'nullable', 'uuid', 'exists:training_rooms,id'], 'trainer_id' => ['sometimes', 'nullable', 'uuid', 'exists:trainers,id'],
        ]);
        abort_if($idx < 1 || $idx > 200, 404);
        if (array_key_exists('date', $d) || array_key_exists('start_time', $d) || array_key_exists('end_time', $d)) {
            $this->need('saber.day.edit');
        }
        if (array_key_exists('room_id', $d)) {
            $this->need('saber.room.assign');
        }
        if (array_key_exists('trainer_id', $d)) {
            $this->need('saber.trainer.assign');
        }
        $rev = $this->drafts->draft($program, $this->user());
        $notices = $this->drafts->setDay($rev, $key, $idx, $d, $this->user());

        return response()->json(['data' => $this->groupOf($program, $rev->fresh(), $key), 'notices' => $notices]);
    }

    public function resetDay(Program $program, string $key, int $idx): JsonResponse
    {
        $this->need('saber.day.edit');
        $rev = $this->drafts->draft($program, $this->user());
        $this->drafts->setDay($rev, $key, $idx, ['date' => null], $this->user());

        return response()->json(['data' => $this->groupOf($program, $rev->fresh(), $key)]);
    }

    public function resetGroup(Program $program, string $key): JsonResponse
    {
        $this->need('saber.day.edit');
        $rev = $this->drafts->draft($program, $this->user());
        $n = $this->drafts->resetGroup($rev, $key, $this->user());

        return response()->json(['data' => $this->groupOf($program, $rev->fresh(), $key), 'reset' => $n]);
    }

    // ── draft and review ─────────────────────────────────────────────────────────────────────────────────────────

    /** The planner's view of their draft: what will change. */
    public function draftDiff(Program $program): JsonResponse
    {
        $rev = $this->drafts->open($program);
        abort_unless($rev, 404);

        return response()->json(['data' => $this->revisionRow($rev) + ['diff' => $this->drafts->diff($rev)]]);
    }

    public function submitDraft(Program $program): JsonResponse
    {
        $rev = $this->drafts->open($program);
        abort_unless($rev, 404);
        abort_if($this->drafts->counts($rev)['groups'] === 0 && $this->drafts->counts($rev)['fields'] === 0 && $program->status !== Program::STATUS_DRAFT, 422, __('messages.saber.review.nothing'));
        $this->drafts->submit($rev, $this->user());

        return response()->json(['data' => $this->rowOf($program->fresh())]);
    }

    /** Throwing a draft away: its author or a reviewer. A programme that only existed as a SABER+ draft goes with it. */
    public function discardDraft(Request $request, Program $program): JsonResponse
    {
        $rev = $this->drafts->open($program);
        abort_unless($rev, 404);
        abort_unless($rev->created_by === $this->user()->id || $this->user()->hasPermission('saber.publish'), 403);
        $this->drafts->discard($rev, $request->input('note'), $this->user());
        $removed = false;
        if ($rev->source === 'new_program' && $program->status === Program::STATUS_DRAFT && ! $program->registrations()->exists()) {
            $program->delete();
            $removed = true;
        }

        return response()->json(['data' => $removed ? ['removed' => true] : $this->rowOf($program->fresh())]);
    }

    /** «مراجعة سابر+»: the drafts sent for review (or every open one, or the history). */
    public function reviews(Request $request): JsonResponse
    {
        $this->need('saber.publish');
        $status = $request->validate(['status' => ['nullable', Rule::in(['submitted', 'draft', 'published', 'discarded'])]])['status'] ?? 'submitted';
        $rows = SaberRevision::with(['program:id,code,title_ar,title_en,status', 'author:id,name,name_ar', 'submitter:id,name,name_ar', 'reviewer:id,name,name_ar', 'editor:id,name,name_ar'])
            ->where('status', $status)->latest($status === 'submitted' ? 'submitted_at' : 'updated_at')->limit(200)->get();

        return response()->json(['data' => $rows->filter(fn ($r) => $r->program)->map(fn ($r) => $this->revisionRow($r) + ['counts' => in_array($r->status, SaberRevision::OPEN, true) ? $this->drafts->counts($r) : null])->values(),
            'meta' => ['counts' => SaberRevision::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')]]);
    }

    public function review(SaberRevision $revision): JsonResponse
    {
        $this->need('saber.publish');

        return response()->json(['data' => $this->revisionRow($revision) + ['diff' => in_array($revision->status, SaberRevision::OPEN, true) ? $this->drafts->diff($revision) : null]]);
    }

    public function publishReview(Request $request, SaberRevision $revision): JsonResponse
    {
        $this->need('saber.publish');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        return response()->json(['data' => $this->drafts->publish($revision, $note, $this->user())]);
    }

    public function returnReview(Request $request, SaberRevision $revision): JsonResponse
    {
        $this->need('saber.publish');
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']])['note'];
        abort_unless(in_array($revision->status, SaberRevision::OPEN, true), 422, __('messages.saber.review.not_open'));

        return response()->json(['data' => $this->revisionRow($this->drafts->returnToDraft($revision, $note, $this->user()))]);
    }

    public function discardReview(Request $request, SaberRevision $revision): JsonResponse
    {
        $this->need('saber.publish');
        abort_unless(in_array($revision->status, SaberRevision::OPEN, true), 422, __('messages.saber.review.not_open'));
        $this->drafts->discard($revision, $request->input('note'), $this->user());
        $program = $revision->program;
        if ($revision->source === 'new_program' && $program && $program->status === Program::STATUS_DRAFT && ! $program->registrations()->exists()) {
            $program->delete();
        }

        return response()->json(['data' => ['discarded' => true]]);
    }

    /** «تفاصيل البرنامج»: everything about a programme and its groups in one window. */
    public function details(Program $program): JsonResponse
    {
        $program->load(['coordinator:id,name,name_ar', 'kits:id,status,title_ar,title_en', 'category', 'groups' => fn ($q) => $q->orderBy('sequence')->withCount(['registrations' => fn ($r) => $r->whereIn('status', ['approved', 'pending', 'waitlisted'])])]);
        $rooms = TrainingRoom::pluck('name_ar', 'id');
        $trainers = Trainer::pluck('name_ar', 'id');
        $row = $this->rowOf($program);
        $row['forms'] = app(FormLinks::class)->one($program);
        $row['groups'] = collect($row['groups'])->map(function ($g) use ($program, $rooms, $trainers) {
            $model = $program->groups->firstWhere('id', $g['id']);
            $g['registrations'] = $model?->registrations_count ?? 0;
            $g['capacity'] = $model?->capacity;
            $g['status_reason'] = $model?->status_reason;
            $g['cancel'] = $model?->cancelled_at ? ['at' => $model->cancelled_at->toIso8601String(), 'requested_by' => $model->cancel_requested_by, 'has_attachment' => (bool) $model->cancel_attachment_path] : null;
            $g['days'] = collect($g['days'])->map(fn ($d) => $d + ['room' => $d['room_id'] ? ($rooms[$d['room_id']] ?? null) : null, 'trainer' => $d['trainer_id'] ? ($trainers[$d['trainer_id']] ?? null) : null])->all();

            return $g;
        })->all();

        return response()->json(['data' => $row + [
            'summary' => $program->summary_ar ?: $program->summary_en, 'objectives' => $program->objectives, 'audience' => $program->audience, 'level' => $program->level,
            'capacity' => $program->capacity, 'category' => $program->category?->name_ar ?? $program->category?->name_en ?? null,
            'kits' => $program->kits->map(fn ($k) => ['id' => $k->id, 'title' => $k->title_ar ?: $k->title_en, 'status' => $k->status])->values(),
            'cancel' => $program->cancelled_at ? ['at' => $program->cancelled_at->toIso8601String(), 'reason' => $program->cancel_reason, 'requested_by' => $program->cancel_requested_by, 'has_attachment' => (bool) $program->cancel_attachment_path] : null,
        ]]);
    }

    /** «خطة الأيام القادمة»: which dates of a weekly pattern are free, taken or closed — nothing is saved. */
    public function planPreview(Request $request, Program $program, string $key, Scheduling $scheduling): JsonResponse
    {
        $this->need('saber.plan.view');
        $rev = $this->drafts->open($program);
        $state = $rev?->state ?? $this->drafts->live($program);
        $max = max($this->drafts->daysPerGroup($program, $state), 1);
        $d = $request->validate([
            'weekday' => ['required', 'integer', 'between:0,6'], 'every' => ['required', 'integer', Rule::in([1, 2])], 'count' => ['required', 'integer', 'min:1', 'max:'.min(200, $max * 4)],
            'from' => ['required', 'date_format:Y-m-d'], 'room_id' => ['nullable', 'uuid', 'exists:training_rooms,id'], 'trainer_id' => ['nullable', 'uuid', 'exists:trainers,id'],
            'start_time' => ['nullable', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i'],
        ]);
        $group = TrainingGroup::where('program_id', $program->id)->find($key) ?? new TrainingGroup(['program_id' => $program->id]);
        $saved = count(collect($state['groups'])->firstWhere('key', $key)['days'] ?? []);

        return response()->json(['data' => $scheduling->preview($group, $d), 'meta' => ['max' => $max, 'saved' => $saved]]);
    }

    /** Applies the chosen dates to the group in the draft, replacing its planned days. */
    public function planApply(Request $request, Program $program, string $key): JsonResponse
    {
        $this->need('saber.day.edit');
        $d = $request->validate([
            'dates' => ['required', 'array', 'min:1', 'max:200'], 'dates.*' => ['date_format:Y-m-d'],
            'room_id' => ['nullable', 'uuid', 'exists:training_rooms,id'], 'trainer_id' => ['nullable', 'uuid', 'exists:trainers,id'],
        ]);
        if (! empty($d['room_id'])) {
            $this->need('saber.room.assign');
        }
        if (! empty($d['trainer_id'])) {
            $this->need('saber.trainer.assign');
        }
        // Every chosen date must be open: the plan dialog only offers open ones, so a closed one means the calendar changed meanwhile.
        $closed = collect($d['dates'])->filter(fn ($date) => app(CalendarService::class)->closedDates(CarbonImmutable::parse($date), CarbonImmutable::parse($date))->isNotEmpty())->values();
        abort_if($closed->isNotEmpty(), response()->json(['message' => __('messages.calendar.closed_for_training', ['dates' => $closed->implode(', ')]), 'code' => 'calendar_closed', 'details' => ['dates' => $closed]], 422));
        $rev = $this->drafts->draft($program, $this->user());
        $result = $this->drafts->replaceDays($rev, $key, collect($d['dates'])->unique()->map(fn ($date) => ['date' => $date, 'room_id' => $d['room_id'] ?? null, 'trainer_id' => $d['trainer_id'] ?? null])->values()->all(), $this->user());
        $notices = [...$result['notices'], ...array_map(fn ($f) => ['type' => 'warning', 'text' => "{$f['date']}: {$f['message']}"], $result['failures'])];
        $planned = count(collect($rev->fresh()->state['groups'])->firstWhere('key', $key)['days'] ?? []);
        $notices[] = ['type' => 'success', 'text' => __('messages.saber.plan.applied', ['n' => $planned])];

        return response()->json(['data' => $this->groupOf($program, $rev->fresh(), $key), 'notices' => array_values(collect($notices)->unique('text')->all())]);
    }

    public function cancelProgram(Request $request, Program $program, Scheduling $scheduling, FileStorage $storage): JsonResponse
    {
        $this->need('saber.program.cancel');
        $scheduling->cancelProgram($program, $this->cancellation($request, $storage, "saber/cancellations/program-{$program->id}"), $this->user());

        return response()->json(['data' => ['cancelled' => true]]);
    }

    public function cancelGroup(Request $request, TrainingGroup $group, Scheduling $scheduling, FileStorage $storage): JsonResponse
    {
        $this->need('saber.program.cancel');
        $scheduling->cancelGroup($group, $this->cancellation($request, $storage, "saber/cancellations/group-{$group->id}"), $this->user());

        return response()->json(['data' => $this->groupOf($group->program, $this->drafts->open($group->program), $group->id)]);
    }

    public function reactivateProgram(Program $program, Scheduling $scheduling): JsonResponse
    {
        $this->need('saber.program.reactivate');

        return response()->json(['data' => ['reactivated' => true], 'notices' => $scheduling->reactivateProgram($program, $this->user())]);
    }

    public function reactivateGroup(TrainingGroup $group, Scheduling $scheduling): JsonResponse
    {
        $this->need('saber.program.reactivate');

        return response()->json(['data' => ['reactivated' => true], 'notices' => $scheduling->reactivateGroup($group, $this->user())]);
    }

    /** «الملغاة»: cancelled programmes, and cancelled groups of programmes that still run. */
    public function cancelled(): JsonResponse
    {
        $programs = Program::where('status', Program::STATUS_CANCELLED)->withCount('groups')->orderByDesc('cancelled_at')->limit(200)->get();
        $groups = TrainingGroup::with('program:id,title_ar,title_en,code,status')->where('status', TrainingGroup::CANCELLED)
            ->whereHas('program', fn ($q) => $q->where('status', '!=', Program::STATUS_CANCELLED))->orderByDesc('cancelled_at')->limit(200)->get();
        $by = User::whereIn('id', $programs->pluck('cancelled_by')->merge($groups->pluck('cancelled_by'))->filter()->unique())->get(['id', 'name', 'name_ar'])->keyBy('id');

        return response()->json(['data' => [
            'programs' => $programs->map(fn ($p) => [
                'id' => $p->id, 'code' => $p->code, 'title' => $p->title_ar ?: $p->title_en, 'groups' => $p->groups_count, 'days' => $this->bench->daysPerGroup($p),
                'reason' => $p->cancel_reason, 'requested_by' => $p->cancel_requested_by, 'cancelled_at' => $p->cancelled_at?->toIso8601String(),
                'cancelled_by' => $p->cancelled_by ? $by[$p->cancelled_by]?->displayName() : null, 'has_attachment' => (bool) $p->cancel_attachment_path,
            ])->values(),
            'groups' => $groups->map(fn ($g) => [
                'id' => $g->id, 'number' => $g->sequence, 'title' => $g->title_ar, 'program' => ['id' => $g->program_id, 'code' => $g->program?->code, 'title' => $g->program?->title_ar ?: $g->program?->title_en],
                'reason' => $g->status_reason, 'requested_by' => $g->cancel_requested_by, 'cancelled_at' => $g->cancelled_at?->toIso8601String(),
                'cancelled_by' => $g->cancelled_by ? $by[$g->cancelled_by]?->displayName() : null, 'has_attachment' => (bool) $g->cancel_attachment_path,
            ])->values(),
        ]]);
    }

    /** A short-lived link to the document attached to a cancellation. */
    public function cancellationAttachment(string $type, string $id, FileStorage $storage): JsonResponse
    {
        $model = $type === 'program' ? Program::findOrFail($id) : TrainingGroup::findOrFail($id);
        abort_unless($model->cancel_attachment_path, 404);

        return response()->json(['data' => ['url' => $storage->temporaryUrl('documents', $model->cancel_attachment_path, 300)]]);
    }

    /** «طلبات أيام الإجازة»: whoever approves the calendar sees every request; everyone else sees their own. */
    public function offdayRequests(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]])['status'] ?? null;
        $approver = $this->user()->hasPermission('calendar.approve');
        $rows = CalendarRequest::with(['program:id,title_ar,title_en', 'trainingGroup:id,sequence,title_ar', 'requester:id,name,name_ar', 'decider:id,name,name_ar'])
            ->when(! $approver, fn ($q) => $q->where('requested_by', $this->user()->id))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (CalendarRequest $r) => $this->requestRow($r))->values(), 'meta' => ['approver' => $approver, 'pending' => CalendarRequest::where('status', 'pending')->when(! $approver, fn ($q) => $q->where('requested_by', $this->user()->id))->count()]]);
    }

    public function requestOffday(Request $request, Scheduling $scheduling): JsonResponse
    {
        $this->need('saber.day.edit');
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'group_id' => ['nullable', 'uuid', 'exists:training_groups,id'], 'day_idx' => ['nullable', 'integer', 'min:1', 'max:200'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'], 'instant' => ['sometimes', 'boolean'],
        ]);
        $group = ! empty($d['group_id']) ? TrainingGroup::find($d['group_id']) : null;
        $row = $scheduling->requestClosedDay($d['date'], $group, $d['day_idx'] ?? null, $d['reason'], $this->user(), (bool) ($d['instant'] ?? false));

        return response()->json(['data' => $this->requestRow($row->fresh(['program', 'trainingGroup', 'requester', 'decider']))], 201);
    }

    public function decideOffday(Request $request, CalendarRequest $calendarRequest, Scheduling $scheduling): JsonResponse
    {
        $d = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->requestRow($scheduling->decide($calendarRequest, $d['approve'], $d['note'] ?? null, $this->user()))]);
    }

    /** «الإجازات الأسبوعية»: weekdays off every week, with a label and whether events may still take place. */
    public function weeklyRules(): JsonResponse
    {
        return response()->json(['data' => [
            'rules' => CalendarWeeklyRule::orderBy('weekday')->get()->map(fn ($r) => ['id' => $r->id, 'weekday' => $r->weekday, 'title_ar' => $r->title_ar, 'title_en' => $r->title_en, 'allow_events' => $r->allow_events])->values(),
            'weekend' => array_values(config('tedc.calendar.weekend', [5, 6])),
        ]]);
    }

    public function saveWeeklyRule(Request $request): JsonResponse
    {
        $d = $request->validate([
            'weekday' => ['required', 'integer', 'between:0,6'], 'title_ar' => ['nullable', 'string', 'max:160'], 'title_en' => ['nullable', 'string', 'max:160'], 'allow_events' => ['sometimes', 'boolean'],
        ]);
        $rule = CalendarWeeklyRule::updateOrCreate(['weekday' => $d['weekday']], $d + ['created_by' => $this->user()->id]);
        $affected = ProgramSession::where('status', '!=', 'cancelled')->where('starts_at', '>=', now())->get(['id', 'starts_at'])
            ->filter(fn ($s) => $s->starts_at->timezone(config('app.timezone'))->dayOfWeek === (int) $d['weekday'])->count();

        return response()->json(['data' => ['id' => $rule->id], 'meta' => ['sessions_on_that_day' => $affected]], 201);
    }

    public function deleteWeeklyRule(CalendarWeeklyRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(null, 204);
    }

    /** @return array{reason: string, requested_by: string, attachment_path: ?string} */
    private function cancellation(Request $request, FileStorage $storage, string $directory): array
    {
        $d = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'], 'requested_by' => ['required', 'string', 'min:2', 'max:160'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,doc,docx'],
        ]);

        return ['reason' => $d['reason'], 'requested_by' => $d['requested_by'], 'attachment_path' => $request->hasFile('attachment') ? $storage->upload($request->file('attachment'), 'documents', $directory) : null];
    }

    /** @return array<string, mixed> */
    private function requestRow(CalendarRequest $r): array
    {
        return [
            'id' => $r->id, 'date' => $r->date->toDateString(), 'reason' => $r->reason, 'status' => $r->status, 'day_idx' => $r->day_idx,
            'program' => $r->program ? ['id' => $r->program->id, 'title' => $r->program->title_ar ?: $r->program->title_en] : null,
            'group' => $r->trainingGroup ? ['id' => $r->trainingGroup->id, 'number' => $r->trainingGroup->sequence, 'title' => $r->trainingGroup->title_ar] : null,
            'requested_by' => $r->requester?->displayName(), 'decided_by' => $r->decider?->displayName(), 'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note, 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** «تقويم القاعات» and «خريطة القاعات»: every room's month, day by day. */
    public function roomsMonth(Request $request, Meetings $meetings): JsonResponse
    {
        $ym = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');

        return response()->json(['data' => $meetings->month($ym)]);
    }

    /** The hour slots of a room on a day (free or held, and by what), with whether the day takes events. */
    public function roomSlots(Request $request, Meetings $meetings): JsonResponse
    {
        $d = $request->validate(['room_id' => ['required', 'uuid', 'exists:training_rooms,id'], 'date' => ['required', 'date_format:Y-m-d'], 'except' => ['nullable', 'uuid']]);

        return response()->json(['data' => $meetings->slots($d['room_id'], $d['date'], $d['except'] ?? null)]);
    }

    /** «حجز قاعة اجتماعات»: by the hour, once or repeated (repeating needs its own right). */
    public function bookMeeting(Request $request, Meetings $meetings): JsonResponse
    {
        abort_unless($this->user()->hasPermission('rooms.book') || $this->user()->hasPermission('saber.program.create') || $this->user()->hasPermission('rooms.manage'), 403);
        $d = $request->validate([
            'room_id' => ['required', 'uuid', 'exists:training_rooms,id'], 'title' => ['required', 'string', 'max:200'], 'requested_by' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:1000'], 'purpose' => ['required', Rule::in(['meeting', 'training', 'event', 'exam', 'other'])], 'date' => ['required', 'date_format:Y-m-d'],
            'from_hour' => ['required', 'integer', 'between:'.Meetings::FIRST_HOUR.','.(Meetings::LAST_HOUR - 1)], 'to_hour' => ['required', 'integer', 'between:'.(Meetings::FIRST_HOUR + 1).','.Meetings::LAST_HOUR],
            'recurrence' => ['nullable', Rule::in(['none', ...array_keys(Meetings::STEPS)])], 'count' => ['nullable', 'integer', 'min:1', 'max:60'], 'attendees' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        if (($d['recurrence'] ?? 'none') !== 'none' && (int) ($d['count'] ?? 1) > 1) {
            $this->need('saber.meeting.recurring');
        } else {
            $d['recurrence'] = null;
            $d['count'] = 1;
        }
        $rows = $meetings->book($d, $this->user());

        return response()->json(['data' => ['created' => $rows->count(), 'series_id' => $rows->first()?->series_id]], 201);
    }

    public function updateMeeting(Request $request, RoomBooking $booking, Meetings $meetings): JsonResponse
    {
        $this->ownMeeting($booking);
        $d = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'], 'requested_by' => ['sometimes', 'nullable', 'string', 'max:160'], 'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'from_hour' => ['sometimes', 'integer', 'between:'.Meetings::FIRST_HOUR.','.(Meetings::LAST_HOUR - 1)], 'to_hour' => ['sometimes', 'integer', 'between:'.(Meetings::FIRST_HOUR + 1).','.Meetings::LAST_HOUR],
            'series' => ['sometimes', 'boolean'],
        ]);
        $meetings->update($booking, $d);

        return response()->json(['data' => ['updated' => true]]);
    }

    public function cancelMeeting(Request $request, RoomBooking $booking, Meetings $meetings): JsonResponse
    {
        $this->ownMeeting($booking);

        return response()->json(['data' => ['cancelled' => $meetings->cancel($booking, $request->boolean('series'))]]);
    }

    private function ownMeeting(RoomBooking $b): void
    {
        $u = $this->user();
        abort_unless($b->status === 'confirmed', 422, __('messages.saber.meeting.not_active'));
        abort_unless($b->booked_by === $u->id || $u->hasPermission('rooms.manage') || $u->hasPermission('logistics.manage'), 403);
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

    /** A programme as the workbench shows it: its open draft when it has one, else the live programme. @return array<string, mixed> */
    private function rowOf(Program $p): array
    {
        $p->loadMissing(['coordinator:id,name,name_ar', 'kits:id,status']);
        $rev = $this->drafts->open($p);
        $state = $rev?->state ?? $this->drafts->live($p);
        $days = $this->drafts->daysPerGroup($p, $state);
        $liveGroups = TrainingGroup::where('program_id', $p->id)->get()->keyBy('id');
        $groups = collect($state['groups'])->reject(fn ($g) => $g['deleted'] ?? false)->sortBy('number')->map(fn ($g) => $this->groupRowOf($p, $g, $days, $liveGroups->get($g['key'])))->values();
        $all = $groups->flatMap(fn ($g) => collect($g['days'])->where('extra', false))->values();
        $dates = $all->pluck('date')->filter()->sort()->values();
        $supervisorId = $this->drafts->field($p, $state, 'coordinator_id');
        $supervisor = $supervisorId ? User::find($supervisorId, ['id', 'name', 'name_ar']) : null;

        return [
            'id' => $p->id, 'code' => $p->code, 'title' => $p->title_ar ?: $p->title_en, 'title_en' => $p->title_en, 'type' => $p->program_type, 'method' => $p->delivery_mode,
            'hours' => (float) $p->total_hours, 'days' => $days, 'status' => $p->status, 'cancelled' => $p->status === Program::STATUS_CANCELLED,
            'kit_ready' => $this->bench->kitReady($p), 'kit_ready_set' => $p->kit_ready !== null,
            'supervisor' => $supervisor ? ['id' => $supervisor->id, 'name' => $supervisor->name_ar ?: $supervisor->name] : null,
            'start_date' => $dates->first() ?? $p->start_date?->toDateString(), 'end_date' => $dates->last() ?? $p->end_date?->toDateString(),
            'lights' => ['total' => $groups->count() * $days, 'dates' => $all->whereNotNull('date')->count(), 'rooms' => $all->whereNotNull('room_id')->count(), 'trainers' => $all->whereNotNull('trainer_id')->count()],
            'groups' => $groups,
            'draft' => $rev ? $this->revisionRow($rev) + ['counts' => $this->drafts->counts($rev)] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function groupOf(Program $program, ?SaberRevision $rev, string $key): array
    {
        $state = $rev?->state ?? $this->drafts->live($program);
        $g = collect($state['groups'])->firstWhere('key', $key);
        abort_unless($g, 404);

        return $this->groupRowOf($program, $g, $this->drafts->daysPerGroup($program, $state), TrainingGroup::find($key));
    }

    /** @return array<string, mixed> */
    private function groupRowOf(Program $program, array $g, int $days, ?TrainingGroup $live): array
    {
        $list = $this->drafts->daysOf($program, $g, $days);
        $first = collect($list)->pluck('date')->filter()->sort()->first();

        return [
            'id' => $g['key'], 'program_id' => $program->id, 'number' => $g['number'], 'title' => $g['title'], 'color' => $g['color'], 'term' => $g['term'] ?? null,
            'term_effective' => Workbench::termOf($g['term'] ?? null, $first), 'status' => $live?->status ?? TrainingGroup::PLANNED, 'cancelled' => $live?->status === TrainingGroup::CANCELLED,
            'validated_at' => $live?->validated_at?->toIso8601String(), 'validated_by_name' => $live?->validated_by_name, 'supervisor_id' => $g['supervisor_id'] ?? null,
            'live' => (bool) ($g['live'] ?? false), 'changed' => collect($list)->contains('changed', true), 'days' => $list,
        ];
    }

    /** @return array<string, mixed> */
    private function revisionRow(SaberRevision $r): array
    {
        $r->loadMissing(['program:id,code,title_ar,title_en,status', 'author:id,name,name_ar', 'editor:id,name,name_ar', 'submitter:id,name,name_ar', 'reviewer:id,name,name_ar']);
        $name = fn ($u) => $u ? ($u->name_ar ?: $u->name) : null;

        return [
            'id' => $r->id, 'status' => $r->status, 'source' => $r->source, 'program' => ['id' => $r->program_id, 'code' => $r->program?->code, 'title' => $r->program?->title_ar ?: $r->program?->title_en, 'status' => $r->program?->status],
            'author' => $name($r->author), 'editor' => $name($r->editor), 'submitted_by' => $name($r->submitter), 'reviewed_by' => $name($r->reviewer),
            'created_at' => $r->created_at?->toIso8601String(), 'updated_at' => $r->updated_at?->toIso8601String(), 'submitted_at' => $r->submitted_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(), 'review_note' => $r->review_note, 'mine' => $r->created_by === $this->user()->id,
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
