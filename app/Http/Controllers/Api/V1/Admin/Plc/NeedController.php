<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcNeed;
use App\Services\Plc\NeedsService;
use App\Services\Plc\WorkshopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The register of incoming needs and proposals (راصد by hand with a reference, the centre, another entity, a school). */
class NeedController extends Controller
{
    public function __construct(private readonly NeedsService $needs, private readonly WorkshopService $workshops) {}

    public function index(Request $request): JsonResponse
    {
        $q = $this->needs->query($this->user())
            ->when($request->query('source'), fn ($b, $v) => $b->where('source', $v))
            ->when($request->query('review_status'), fn ($b, $v) => $b->whereIn('review_status', explode(',', $v)))
            ->when($request->query('q'), fn ($b, $v) => $b->where(fn ($w) => $w->where('title', 'like', "%$v%")->orWhere('reference_no', 'like', "%$v%")))
            ->orderByDesc('created_at');
        $page = $q->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (PlcNeed $n) => $this->needs->present($n))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $need = $this->needs->create($this->user(), $this->validated($request, true));

        return response()->json(['data' => $this->needs->present($need)], 201);
    }

    public function show(PlcNeed $plcNeed): JsonResponse
    {
        $this->assertVisible($plcNeed);

        return response()->json(['data' => $this->needs->present($plcNeed) + ['workshop_list' => $plcNeed->workshops()->with('program:id,code,title_ar,title_en')->get()->map(fn ($w) => ['id' => $w->program_id, 'code' => $w->program?->code, 'title_ar' => $w->program?->title_ar, 'state' => $w->workflow_state])]]);
    }

    public function update(Request $request, PlcNeed $plcNeed): JsonResponse
    {
        $this->assertVisible($plcNeed);

        return response()->json(['data' => $this->needs->present($this->needs->update($plcNeed, $this->user(), $this->validated($request, false) + $request->only('review_status')))]);
    }

    public function convert(Request $request, PlcNeed $plcNeed): JsonResponse
    {
        $this->assertVisible($plcNeed);
        $data = $request->validate([
            'scenario' => ['sometimes', 'in:shared,internal'], 'title_ar' => ['nullable', 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'],
            'host_school_id' => ['nullable', 'uuid', 'exists:schools,id'], 'community_id' => ['nullable', 'uuid', 'exists:plc_communities,id'],
            'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'delivery_mode' => ['sometimes', 'in:in_person,online,hybrid'],
            'expected_participants' => ['nullable', 'integer', 'min:1', 'max:5000'], 'priority' => ['sometimes', 'in:low,normal,high,urgent'],
        ]);
        $w = $this->needs->convert($plcNeed, $this->user(), array_filter($data, fn ($v) => $v !== null));

        return response()->json(['data' => $this->workshops->detail($w, $this->user())], 201);
    }

    private function assertVisible(PlcNeed $need): void
    {
        abort_unless($this->needs->query($this->user())->whereKey($need->id)->exists(), 403, __('messages.plc.outside_scope'));
    }

    private function validated(Request $request, bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'source' => [$r, Rule::in(PlcNeed::SOURCES)], 'reference_no' => ['nullable', 'string', 'max:64'],
            'title' => [$r, 'string', 'max:300'], 'description' => ['nullable', 'string', 'max:5000'], 'domain' => ['nullable', 'string', 'max:64'],
            'target_audience' => ['nullable', 'string', 'max:200'], 'received_at' => ['nullable', 'date'], 'source_url' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'], 'school_ids' => ['nullable', 'array', 'max:200'], 'school_ids.*' => ['uuid', 'exists:schools,id'],
            'entity_name' => ['nullable', 'string', 'max:200'], 'proposer_name' => ['nullable', 'string', 'max:200'],
            'justification' => ['nullable', 'string', 'max:2000'], 'expected_outcomes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
