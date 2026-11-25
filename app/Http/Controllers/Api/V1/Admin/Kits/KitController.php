<?php

namespace App\Http\Controllers\Api\V1\Admin\Kits;

use App\Http\Resources\KitFileResource;
use App\Http\Resources\KitResource;
use App\Models\KitActivity;
use App\Models\KitComment;
use App\Models\KitMember;
use App\Models\Program;
use App\Models\Role;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\Kits\KitAccess;
use App\Services\Kits\KitInsights;
use App\Services\Kits\KitLog;
use App\Services\Kits\KitWorkflow;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Training kits (الحقائب التدريبية): list, board, details, members, workflow. */
class KitController extends KitBaseController
{
    public function __construct(private readonly KitInsights $insights, private readonly KitWorkflow $workflow) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user();
        $kits = $this->query($request, $user)->with(['owner', 'category', 'program', 'members.user'])->withCount('files')
            ->latest('updated_at')->paginate($this->perPage($request, 24));
        $this->decorate($kits->getCollection());

        return KitResource::collection($kits);
    }

    /** Kits grouped by lifecycle status for the board view. */
    public function board(Request $request): JsonResponse
    {
        $user = $this->user();
        $base = $this->query($request, $user);
        $counts = (clone $base)->reorder()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        $columns = collect(TrainingKit::STATUSES)->reject(fn ($s) => $s === TrainingKit::ARCHIVED && ! $request->boolean('archived'))->map(function ($status) use ($base, $counts) {
            $items = (clone $base)->where('status', $status)->with(['owner', 'category', 'program', 'members.user'])->withCount('files')->latest('updated_at')->limit(30)->get();
            $this->decorate($items);

            return ['status' => $status, 'count' => (int) ($counts[$status] ?? 0), 'items' => KitResource::collection($items)];
        })->values();

        return response()->json(['data' => $columns]);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $this->user();
        $all = KitAccess::scope(TrainingKit::query(), $user)->where('status', '!=', TrainingKit::ARCHIVED);
        $byDelivery = (clone $all)->select('delivery', DB::raw('count(*) as n'))->groupBy('delivery')->pluck('n', 'delivery');
        $kits = KitAccess::scope(TrainingKit::query(), $user)
            ->when(in_array($request->query('delivery'), TrainingKit::DELIVERIES, true), fn ($q) => $q->where('delivery', $request->query('delivery')));
        $ids = (clone $kits)->pluck('id');

        $byStatus = (clone $kits)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $mine = KitComment::whereIn('kit_id', $ids)->whereNull('parent_id')->where('assignee_id', $user->id)->whereIn('status', ['open', 'addressed']);

        return response()->json(['data' => [
            'by_delivery' => collect(TrainingKit::DELIVERIES)->mapWithKeys(fn ($d) => [$d => (int) ($byDelivery[$d] ?? 0)])->all(),
            'total' => (int) $byStatus->except(TrainingKit::ARCHIVED)->sum(),
            'by_status' => $byStatus,
            'awaiting_review' => (clone $kits)->where('status', TrainingKit::IN_REVIEW)->count(),
            'awaiting_my_review' => KitAccess::isStaff($user) || $user->hasPermission('kits.review')
                ? (clone $kits)->where('status', TrainingKit::IN_REVIEW)->where(fn ($q) => $q->whereHas('members', fn ($m) => $m->where('user_id', $user->id)->whereIn('role', [KitMember::QA, KitMember::REVIEWER])))->count() : 0,
            'needs_my_changes' => (clone $kits)->where('status', TrainingKit::CHANGES_REQUESTED)->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)->where('role', KitMember::DEVELOPER)))->count(),
            'my_open_comments' => (clone $mine)->count(),
            'overdue' => (clone $kits)->whereNotNull('due_at')->where('due_at', '<', now())->whereNotIn('status', [TrainingKit::APPROVED, TrainingKit::PUBLISHED, TrainingKit::ARCHIVED])->count(),
            'open_comments' => KitComment::whereIn('kit_id', $ids)->whereNull('parent_id')->where('status', '!=', 'resolved')->count(),
            'recent' => KitActivity::whereIn('kit_id', $ids)->with('user')->latest('created_at')->limit(12)->get()->map(fn ($a) => $this->activityRow($a, true)),
        ]]);
    }

    public function show(TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $kit->load(['owner', 'category', 'program', 'members.user', 'files' => fn ($q) => $q->with(['uploader', 'updater'])->withCount(['comments as open_comments' => fn ($c) => $c->whereNull('parent_id')->where('status', '!=', 'resolved')])]);
        $kit->loadCount('files');
        $this->decorate(collect([$kit]));

        $review = $kit->reviews()->with(['submitter', 'decider'])->first();

        return response()->json(['data' => (new KitResource($kit))->resolve() + [
            'files' => KitFileResource::collection($kit->files),
            'completeness' => $this->insights->completeness($kit),
            'review' => $review ? [
                'round' => $review->round, 'status' => $review->status, 'submitted_by' => $review->submitter?->displayName(), 'submitted_at' => $review->submitted_at?->toIso8601String(),
                'decided_by' => $review->decider?->displayName(), 'decided_at' => $review->decided_at?->toIso8601String(), 'note' => $review->note, 'summary' => $review->summary,
            ] : null,
        ]]);
    }

    public function reviews(TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);

        return response()->json(['data' => $kit->reviews()->with(['submitter', 'decider'])->get()->map(fn ($r) => [
            'round' => $r->round, 'status' => $r->status, 'submitted_by' => $r->submitter?->displayName(), 'submitted_at' => $r->submitted_at?->toIso8601String(),
            'decided_by' => $r->decider?->displayName(), 'decided_at' => $r->decided_at?->toIso8601String(), 'note' => $r->note, 'summary' => $r->summary,
        ])]);
    }

    public function store(Request $request, NotificationService $notifications): JsonResponse
    {
        $user = $this->user();
        abort_unless($user->hasPermission('kits.manage'), 403, __('auth.forbidden'));
        $data = $this->validated($request);

        $kit = DB::transaction(function () use ($data, $user) {
            $program = ! empty($data['program_id']) ? Program::find($data['program_id']) : null;
            // A kit made for a program is of that program's kind; a free-standing kit takes the kind it is given.
            $data['delivery'] = $program && in_array($program->delivery_mode, TrainingKit::DELIVERIES, true) ? $program->delivery_mode : ($data['delivery'] ?? 'in_person');
            $kit = TrainingKit::create($data + [
                'code' => $this->nextCode(),
                'status' => TrainingKit::DRAFT,
                'owner_id' => $data['owner_id'] ?? $user->id,
                'created_by' => $user->id,
                'title_ar' => $data['title_ar'] ?? $program?->title_ar,
                'title_en' => $data['title_en'] ?? $program?->title_en,
            ]);
            $this->syncMembers($kit, $data['members'] ?? [], $user);
            KitLog::record($kit, $user, 'created', 'kit', $kit->id);

            return $kit;
        });

        return $this->show($kit->refresh())->setStatusCode(201);
    }

    public function update(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->manageable($kit);
        $data = $this->validated($request, true);
        unset($data['members']);
        $program = ($data['program_id'] ?? $kit->program_id) ? Program::find($data['program_id'] ?? $kit->program_id) : null;
        if ($program && in_array($program->delivery_mode, TrainingKit::DELIVERIES, true)) {
            $data['delivery'] = $program->delivery_mode;   // the program decides
        }
        $kit->update($data);
        KitLog::record($kit, $this->user(), 'updated', 'kit', $kit->id);

        return $this->show($kit->refresh());
    }

    public function destroy(TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        abort_unless(KitAccess::canPublish($this->user()) || ($kit->owner_id === $this->user()->id && $kit->status === TrainingKit::DRAFT), 403, __('auth.forbidden'));
        $kit->delete();

        return response()->json(null, 204);
    }

    public function updateMembers(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->manageable($kit);
        $data = $request->validate([
            'owner_id' => ['sometimes', 'uuid', 'exists:users,id'],
            'members' => ['present', 'array', 'max:40'],
            'members.*.user_id' => ['required', 'uuid', 'exists:users,id', 'distinct'],
            'members.*.role' => ['required', Rule::in(KitMember::ROLES)],
        ]);
        DB::transaction(function () use ($kit, $data) {
            if (isset($data['owner_id'])) {
                $kit->update(['owner_id' => $data['owner_id']]);
            }
            $this->syncMembers($kit, $data['members'], $this->user());
        });
        KitLog::record($kit, $this->user(), 'members_updated', 'kit', $kit->id);

        return $this->show($kit->refresh());
    }

    /** People who can be added to a kit team, with their studio role. */
    public function people(Request $request): JsonResponse
    {
        abort_unless($this->user()->hasPermission('kits.view'), 403);
        $users = User::with('roles')->where('status', 'active')
            ->whereHas('roles', fn ($r) => $r->whereIn('slug', [Role::KIT_DEVELOPER, Role::QA_REVIEWER, Role::COORDINATOR, Role::CENTER_ADMIN, Role::SUPER_ADMIN, Role::TRAINER]))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('name', "%{$t}%")->orWhereLike('name_ar', "%{$t}%")->orWhereLike('email', "%{$t}%")))
            ->orderBy('name')->limit(80)->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->displayName(), 'email' => $u->email,
            'suggested_role' => $u->hasRole(Role::QA_REVIEWER) ? KitMember::QA : ($u->hasRole(Role::KIT_DEVELOPER) ? KitMember::DEVELOPER : KitMember::REVIEWER),
            'roles' => $u->roles->pluck('slug')->all(),
        ])]);
    }

    public function activity(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $page = $kit->activity()->with('user')->paginate($this->perPage($request, 30));

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => $this->activityRow($a)), 'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()]]);
    }

    public function suggestions(TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);

        return response()->json(['data' => $this->insights->suggestions($kit)]);
    }

    // Workflow ---------------------------------------------------------------------------------

    public function submit(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->manageable($kit);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->workflow->submit($kit, $this->user(), $data['note'] ?? null);

        return $this->show($kit->refresh());
    }

    public function requestChanges(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        abort_unless(KitAccess::canReview($this->user(), $kit), 403, __('auth.forbidden'));
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:2000']]);
        $this->workflow->requestChanges($kit, $this->user(), $data['note']);

        return $this->show($kit->refresh());
    }

    public function approve(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        abort_unless(KitAccess::canReview($this->user(), $kit), 403, __('auth.forbidden'));
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000'], 'force' => ['sometimes', 'boolean']]);
        $this->workflow->approve($kit, $this->user(), $data['note'] ?? null, (bool) ($data['force'] ?? false));

        return $this->show($kit->refresh());
    }

    public function publish(TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        abort_unless(KitAccess::canPublish($this->user()), 403, __('auth.forbidden'));
        $this->workflow->publish($kit, $this->user());

        return $this->show($kit->refresh());
    }

    public function reopen(TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        abort_unless(KitAccess::canPublish($this->user()) || $kit->owner_id === $this->user()->id, 403, __('auth.forbidden'));
        $this->workflow->reopen($kit, $this->user());

        return $this->show($kit->refresh());
    }

    public function archive(TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        abort_unless(KitAccess::canPublish($this->user()), 403, __('auth.forbidden'));
        $this->workflow->archive($kit, $this->user());

        return $this->show($kit->refresh());
    }

    // Helpers ----------------------------------------------------------------------------------

    private function query(Request $request, User $user)
    {
        return KitAccess::scope(TrainingKit::query(), $user)
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('title_ar', "%{$t}%")->orWhereLike('title_en', "%{$t}%")->orWhereLike('code', "%{$t}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->boolean('mine'), fn ($q) => $q->where(fn ($w) => $w->where('owner_id', $user->id)->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id))))
            ->when(in_array($request->query('delivery'), TrainingKit::DELIVERIES, true), fn ($q) => $q->where('delivery', $request->query('delivery')))
            ->when($request->query('program_id'), fn ($q, $id) => $q->where('program_id', $id))
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereNotNull('due_at')->where('due_at', '<', now())->whereNotIn('status', [TrainingKit::APPROVED, TrainingKit::PUBLISHED, TrainingKit::ARCHIVED]))
            ->when(! $request->query('status') && ! $request->boolean('archived'), fn ($q) => $q->where('status', '!=', TrainingKit::ARCHIVED));
    }

    /** Adds comment counters and the completeness percentage to a set of kits. */
    private function decorate($kits): void
    {
        $ids = $kits->pluck('id');
        $open = KitComment::whereIn('kit_id', $ids)->whereNull('parent_id')->where('status', '!=', 'resolved')->select('kit_id', DB::raw('count(*) as n'))->groupBy('kit_id')->pluck('n', 'kit_id');
        $blocking = KitComment::whereIn('kit_id', $ids)->whereNull('parent_id')->whereIn('severity', KitComment::BLOCKING)->where('status', '!=', 'resolved')->select('kit_id', DB::raw('count(*) as n'))->groupBy('kit_id')->pluck('n', 'kit_id');
        foreach ($kits as $kit) {
            $kit->open_comments = (int) ($open[$kit->id] ?? 0);
            $kit->blocking_comments = (int) ($blocking[$kit->id] ?? 0);
            $kit->progress = $this->insights->completeness($kit)['percent'];
        }
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title_ar' => [$partial ? 'sometimes' : 'required_without:program_id', 'string', 'max:255'],
            'title_en' => [$partial ? 'sometimes' : 'required_without:program_id', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'], 'description_en' => ['nullable', 'string', 'max:5000'],
            'program_id' => ['nullable', 'uuid', 'exists:programs,id'],
            'delivery' => ['sometimes', Rule::in(TrainingKit::DELIVERIES)],
            'category_id' => ['nullable', 'uuid', 'exists:program_categories,id'],
            'audience' => ['nullable', 'string', 'max:255'],
            'duration_hours' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'objectives' => ['nullable', 'array', 'max:20'], 'objectives.*' => ['string', 'max:500'],
            'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:40'],
            'due_at' => ['nullable', 'date'],
            'owner_id' => ['sometimes', 'uuid', 'exists:users,id'],
            'members' => ['sometimes', 'array', 'max:40'],
            'members.*.user_id' => ['required', 'uuid', 'exists:users,id', 'distinct'],
            'members.*.role' => ['required', Rule::in(KitMember::ROLES)],
        ]);
    }

    private function syncMembers(TrainingKit $kit, array $members, User $actor): void
    {
        $before = $kit->members()->pluck('user_id')->all();
        $keep = [];
        foreach ($members as $m) {
            if ($m['user_id'] === $kit->owner_id) {
                continue;
            }
            KitMember::updateOrCreate(['kit_id' => $kit->id, 'user_id' => $m['user_id']], ['role' => $m['role']]);
            $keep[] = $m['user_id'];
        }
        $kit->members()->whereNotIn('user_id', $keep)->delete();

        foreach (array_diff($keep, $before) as $userId) {
            app(NotificationService::class)->send($userId, 'kit.assigned', ['ar' => 'أُضفت إلى فريق حقيبة تدريبية', 'en' => 'You were added to a training kit team'],
                ['ar' => "«{$kit->title_ar}» — بواسطة {$actor->displayName()}", 'en' => "\"{$kit->title_en}\" - by {$actor->displayName()}"], ['kit_id' => $kit->id]);
        }
    }

    private function nextCode(): string
    {
        $prefix = 'KIT-'.now()->format('y').'-';
        $n = TrainingKit::withoutGlobalScopes()->where('code', 'like', $prefix.'%')->count() + 1;
        while (TrainingKit::where('code', $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    private function activityRow(KitActivity $a, bool $withKit = false): array
    {
        return [
            'id' => $a->id, 'kit_id' => $a->kit_id, 'action' => $a->action, 'subject_type' => $a->subject_type, 'subject_id' => $a->subject_id, 'meta' => $a->meta ?? [],
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->displayName()] : null, 'created_at' => $a->created_at?->toIso8601String(),
        ] + ($withKit ? ['kit' => TrainingKit::find($a->kit_id)?->only(['id', 'code', 'title_ar', 'title_en'])] : []);
    }
}
