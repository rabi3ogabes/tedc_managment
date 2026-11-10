<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcCommunity;
use App\Services\Plc\CommunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Professional learning communities: the frame that gathers schools around a domain and its workshops. */
class CommunityController extends Controller
{
    public function __construct(private readonly CommunityService $communities) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->communities->query($this->user())
            ->when($request->query('status', 'active'), fn ($b, $v) => $v === 'all' ? $b : $b->where('status', $v))
            ->when($request->query('q'), fn ($b, $v) => $b->where(fn ($w) => $w->where('title_ar', 'like', "%$v%")->orWhere('title_en', 'like', "%$v%")->orWhere('code', 'like', "%$v%")))
            ->orderByDesc('updated_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (PlcCommunity $c) => $this->communities->present($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $c = $this->communities->create($this->user(), $this->validated($request, true));

        return response()->json(['data' => $this->communities->present($c->load('schools'))], 201);
    }

    public function show(PlcCommunity $plcCommunity): JsonResponse
    {
        $this->assertVisible($plcCommunity);

        return response()->json(['data' => $this->communities->present($plcCommunity->load('schools', 'lead', 'hostSchool'))]);
    }

    public function update(Request $request, PlcCommunity $plcCommunity): JsonResponse
    {
        $this->assertVisible($plcCommunity);
        $c = $this->communities->update($plcCommunity, $this->user(), $this->validated($request, false));

        return response()->json(['data' => $this->communities->present($c->load('schools'))]);
    }

    public function destroy(PlcCommunity $plcCommunity): JsonResponse
    {
        $this->assertVisible($plcCommunity);

        return response()->json(['data' => $this->communities->present($this->communities->archive($plcCommunity, $this->user()))]);
    }

    private function assertVisible(PlcCommunity $c): void
    {
        abort_unless($this->communities->query($this->user())->whereKey($c->id)->exists(), 403, __('messages.plc.outside_scope'));
    }

    private function validated(Request $request, bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title_ar' => [$r, 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'],
            'description_ar' => ['nullable', 'string', 'max:2000'], 'description_en' => ['nullable', 'string', 'max:2000'],
            'domain' => ['nullable', 'string', 'max:64'], 'lead_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'host_school_id' => ['nullable', 'uuid', 'exists:schools,id'], 'space_id' => ['nullable', 'uuid', 'exists:spaces,id'],
            'school_ids' => ['nullable', 'array', 'max:300'], 'school_ids.*' => ['uuid', 'exists:schools,id'],
        ]);
    }
}
