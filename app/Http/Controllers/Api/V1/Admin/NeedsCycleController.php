<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\InstitutionalRequest;
use App\Models\NeedsCycle;
use App\Models\Program;
use App\Models\ProgramProposal;
use App\Services\NeedsIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Needs cycle, department proposals and manager requests. */
class NeedsCycleController extends Controller
{
    public function __construct(private readonly NeedsIntakeService $intake) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => NeedsCycle::orderByDesc('year')->orderByDesc('created_at')->get()->map(fn ($c) => $this->present($c))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        return response()->json(['data' => $this->present(NeedsCycle::create($data + ['status' => NeedsCycle::DRAFT]))], 201);
    }

    public function show(NeedsCycle $cycle): JsonResponse
    {
        $proposals = ProgramProposal::where('cycle_id', $cycle->id)->get();
        $requests = InstitutionalRequest::where('cycle_id', $cycle->id)->get();

        return response()->json(['data' => $this->present($cycle) + [
            'progress' => [
                'proposals' => $proposals->countBy('status')->all(), 'requests' => $requests->countBy('status')->all(),
                'entities' => $proposals->groupBy('entity_name')->map->count()->sortDesc()->all(),
            ],
        ]]);
    }

    public function update(Request $request, NeedsCycle $cycle): JsonResponse
    {
        $cycle->update($request->validate(array_map(fn ($r) => array_merge(['sometimes'], array_diff($r, ['required'])), $this->rules())));

        return response()->json(['data' => $this->present($cycle->fresh())]);
    }

    public function open(NeedsCycle $cycle): JsonResponse
    {
        return response()->json(['data' => $this->present($this->intake->open($cycle))]);
    }

    public function close(NeedsCycle $cycle): JsonResponse
    {
        return response()->json(['data' => $this->present($this->intake->close($cycle))]);
    }

    public function proposals(NeedsCycle $cycle): JsonResponse
    {
        $user = $this->user();
        $rows = ProgramProposal::where('cycle_id', $cycle->id)->when(! $user->hasPermission('needs.cycles'), fn ($q) => $q->where('submitted_by', $user->id))->orderBy('priority_rank')->orderByDesc('importance')->get();

        return response()->json(['data' => $rows->all()]);
    }

    public function storeProposal(Request $request, NeedsCycle $cycle): JsonResponse
    {
        return response()->json(['data' => $this->intake->submitProposal($cycle, $request->validate($this->proposalRules(true)), $this->user())], 201);
    }

    public function updateProposal(Request $request, ProgramProposal $proposal): JsonResponse
    {
        abort_unless($proposal->submitted_by === $this->user()->id || $this->user()->hasPermission('needs.cycles'), 403);

        return response()->json(['data' => $this->intake->updateProposal($proposal, $request->validate($this->proposalRules(false)), $this->user())]);
    }

    public function reviewProposal(Request $request, ProgramProposal $proposal): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['under_review', 'accepted', 'merged', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000'], 'existing_program_id' => ['nullable', 'uuid', 'exists:programs,id'], 'priority_rank' => ['nullable', 'integer', 'min:1', 'max:1000'], 'existing_program_code' => ['nullable', 'string', 'exists:programs,code']]);
        if (! empty($d['existing_program_code'])) {
            $d['existing_program_id'] = Program::where('code', $d['existing_program_code'])->value('id');
        }

        return response()->json(['data' => $this->intake->reviewProposal($proposal, $d['decision'], $d['note'] ?? null, $d['existing_program_id'] ?? null, $d['priority_rank'] ?? null, $this->user())]);
    }

    public function requests(): JsonResponse
    {
        $user = $this->user();
        $rows = InstitutionalRequest::when(! $user->hasPermission('needs.cycles'), fn ($q) => $q->where('requested_by', $user->id))->latest()->get();

        return response()->json(['data' => $rows->all()]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program_id' => ['nullable', 'uuid', 'exists:programs,id'], 'title' => ['required_without:program_id', 'nullable', 'string', 'max:255'], 'need_degree' => ['required', 'integer', 'between:1,5'],
            'objectives' => ['nullable', 'array', 'max:20'], 'objectives.*' => ['string', 'max:500'], 'employee_ids' => ['nullable', 'array', 'max:500'], 'employee_ids.*' => ['uuid'], 'employee_nos' => ['nullable', 'array', 'max:500'], 'employee_nos.*' => ['string', 'max:40'], 'preferred_window' => ['nullable', 'string', 'max:120'],
        ]);

        if (! empty($data['employee_nos'])) {
            $data['employee_ids'] = array_merge($data['employee_ids'] ?? [], Employee::whereIn('employee_no', $data['employee_nos'])->pluck('id')->all());
        }
        unset($data['employee_nos']);

        return response()->json(['data' => $this->intake->submitRequest($data, $this->user())], 201);
    }

    public function reviewRequest(Request $request, InstitutionalRequest $institutionalRequest): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['accepted', 'merged', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000'], 'program_id' => ['nullable', 'uuid', 'exists:programs,id']]);

        return response()->json(['data' => $this->intake->reviewRequest($institutionalRequest, $d['decision'], $d['note'] ?? null, $d['program_id'] ?? null, $this->user())]);
    }

    private function rules(): array
    {
        return ['year' => ['required', 'integer', 'min:2024', 'max:2100'], 'title_ar' => ['required', 'string', 'max:200'], 'title_en' => ['required', 'string', 'max:200'], 'opens_at' => ['nullable', 'date'], 'closes_at' => ['nullable', 'date'], 'plan_id' => ['nullable', 'uuid', 'exists:training_plans,id'], 'settings' => ['nullable', 'array'], 'settings.reminder_days' => ['nullable', 'integer', 'between:1,30']];
    }

    private function proposalRules(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return [
            'program_title_ar' => [$r, 'string', 'max:255'], 'program_title_en' => ['sometimes', 'nullable', 'string', 'max:255'], 'existing_program_id' => ['sometimes', 'nullable', 'uuid', 'exists:programs,id'],
            'groups_count' => ['sometimes', 'integer', 'between:1,200'], 'axes' => ['sometimes', 'nullable', 'array', 'max:30'], 'axes.*' => ['string', 'max:255'], 'target_job_title_ids' => ['sometimes', 'nullable', 'array'], 'target_job_title_ids.*' => ['uuid'],
            'target_description' => ['sometimes', 'nullable', 'string', 'max:2000'], 'days' => ['sometimes', 'integer', 'between:1,365'], 'hours' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'kit_availability' => ['sometimes', Rule::in(['available', 'partial', 'none'])], 'trainer_nominations' => ['sometimes', 'nullable', 'array', 'max:20'], 'importance' => ['sometimes', 'integer', 'between:1,5'], 'justification' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ];
    }

    private function present(NeedsCycle $c): array
    {
        return ['id' => $c->id, 'year' => $c->year, 'title_ar' => $c->title_ar, 'title_en' => $c->title_en, 'status' => $c->status, 'is_open' => $c->isOpen(), 'opens_at' => $c->opens_at?->toIso8601String(), 'closes_at' => $c->closes_at?->toIso8601String(), 'plan_id' => $c->plan_id, 'settings' => $c->settings];
    }
}
