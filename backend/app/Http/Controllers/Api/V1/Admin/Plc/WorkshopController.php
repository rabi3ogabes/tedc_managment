<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcWorkshop;
use App\Services\Plc\PlcSettings;
use App\Services\Plc\WorkshopService;
use App\Services\Plc\WorkshopWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** PLC workshops: creation on the programme engine, drafts, the review path and the lifecycle. */
class WorkshopController extends Controller
{
    public function __construct(private readonly WorkshopService $workshops, private readonly WorkshopWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $q = $this->workshops->query($this->user())
            ->when($request->query('state'), fn ($b, $v) => $b->whereIn('workflow_state', explode(',', $v)))
            ->when($request->query('scenario'), fn ($b, $v) => $b->where('scenario', $v))
            ->when($request->query('source'), fn ($b, $v) => $b->where('source', $v))
            ->when($request->query('community_id'), fn ($b, $v) => $b->where('community_id', $v))
            ->when($request->query('school_id'), fn ($b, $v) => $b->where(fn ($w) => $w->where('host_school_id', $v)->orWhereHas('schools', fn ($s) => $s->where('school_id', $v))))
            ->when($request->query('q'), fn ($b, $v) => $b->whereHas('program', fn ($p) => $p->where(fn ($w) => $w->where('title_ar', 'like', "%$v%")->orWhere('title_en', 'like', "%$v%")->orWhere('code', 'like', "%$v%"))))
            ->orderByDesc('updated_at');
        $page = $q->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (PlcWorkshop $w) => $this->workshops->summary($w))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Lists, states and policy facts the hub needs; says plainly that راصد has no electronic integration. */
    public function catalog(PlcSettings $settings): JsonResponse
    {
        $user = $this->user();

        return response()->json(['data' => [
            'states' => WorkshopWorkflow::STATES, 'transitions' => WorkshopWorkflow::TRANSITIONS, 'editable_states' => WorkshopWorkflow::EDITABLE,
            'sources' => PlcWorkshop::SOURCES, 'scenarios' => PlcWorkshop::SCENARIOS, 'priorities' => PlcWorkshop::PRIORITIES,
            'delivery_modes' => ['in_person', 'online', 'hybrid'],
            'approval_paths' => ['internal' => $settings->approvalPath('internal'), 'shared' => $settings->approvalPath('shared')],
            'rased_integration' => (bool) $settings->get('rased_integration'),
            'can' => ['manage' => $user->hasPermission('plc.manage'), 'internal' => $user->hasPermission('plc.internal.submit'), 'approve' => $user->hasPermission('plc.approve'),
                'approve_school' => $user->hasPermission('plc.approve.school'), 'communities' => $user->hasPermission('plc.community.manage'), 'settings' => $user->hasPermission('plc.settings'), 'exceptions' => $user->hasPermission('plc.exceptions')],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $w = $this->workshops->create($this->user(), $this->validated($request, true));

        return response()->json(['data' => $this->workshops->detail($w, $this->user())], 201);
    }

    public function show(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->workshops->detail($plcWorkshop, $this->user())]);
    }

    public function update(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $w = $this->workshops->update($plcWorkshop, $this->user(), $this->validated($request, false) + $request->only('exception_reason'));

        return response()->json(['data' => $this->workshops->detail($w, $this->user())]);
    }

    public function submit(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return $this->after($this->workflow->submit($plcWorkshop, $this->user()));
    }

    public function approve(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->after($this->workflow->approve($plcWorkshop, $this->user(), $data['note'] ?? null));
    }

    public function requestChanges(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->after($this->workflow->requestChanges($plcWorkshop, $this->user(), $data['reason']));
    }

    public function reject(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->after($this->workflow->reject($plcWorkshop, $this->user(), $data['reason']));
    }

    public function cancel(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $user = $this->user();
        abort_unless($user->hasPermission('plc.manage') || $plcWorkshop->program->created_by === $user->id, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->after($this->workflow->cancel($plcWorkshop, $user, $data['reason']));
    }

    private function after(PlcWorkshop $w): JsonResponse
    {
        return response()->json(['data' => $this->workshops->detail($w, $this->user())]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'scenario' => ['sometimes', Rule::in(PlcWorkshop::SCENARIOS)],
            'title_ar' => [$r, 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'],
            'summary_ar' => ['nullable', 'string', 'max:2000'], 'summary_en' => ['nullable', 'string', 'max:2000'],
            'objectives' => ['nullable', 'array', 'max:20'], 'objectives.*' => ['string', 'max:500'],
            'outcomes' => ['nullable', 'string', 'max:2000'], 'prerequisites' => ['nullable', 'string', 'max:2000'],
            'domain' => ['nullable', 'string', 'max:64'], 'target_audience' => ['nullable', 'string', 'max:200'],
            'source' => ['sometimes', Rule::in(PlcWorkshop::SOURCES)], 'source_ref' => ['nullable', 'string', 'max:64'], 'need_id' => ['nullable', 'uuid', 'exists:plc_needs,id'],
            'organiser' => ['nullable', 'string', 'max:200'], 'community_id' => ['nullable', 'uuid', 'exists:plc_communities,id'],
            'coordinator_user_id' => ['nullable', 'uuid', 'exists:users,id'], 'priority' => ['sometimes', Rule::in(PlcWorkshop::PRIORITIES)],
            'expected_participants' => ['nullable', 'integer', 'min:1', 'max:5000'], 'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'host_school_id' => ['nullable', 'uuid', 'exists:schools,id'],
            'delivery_mode' => ['sometimes', Rule::in(['in_person', 'online', 'hybrid'])], 'total_hours' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'min_attendance_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
    }
}
