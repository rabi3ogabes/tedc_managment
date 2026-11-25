<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcWorkshop;
use App\Services\Plc\SchoolSelector;
use App\Services\Plc\WorkshopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The schools step of a workshop: host, manual search, suggestions, seats, exclusions. */
class WorkshopSchoolController extends Controller
{
    public function __construct(private readonly SchoolSelector $selector, private readonly WorkshopService $workshops) {}

    public function index(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->selector->current($plcWorkshop)]);
    }

    public function search(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'region' => ['nullable', 'string', 'max:32'], 'type' => ['nullable', 'string', 'max:32'], 'gender' => ['nullable', 'string', 'max:16'], 'stage' => ['nullable', 'string', 'max:32']]);

        return response()->json(['data' => $this->selector->search($plcWorkshop, $this->user(), array_filter($filters, fn ($v) => $v !== null && $v !== ''))]);
    }

    public function suggest(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['max_km' => ['nullable', 'integer', 'min:1', 'max:500'], 'limit' => ['nullable', 'integer', 'min:1', 'max:60']]);

        return response()->json(['data' => $this->selector->suggest($plcWorkshop, $this->user(), $data['max_km'] ?? null, (int) ($data['limit'] ?? 20))]);
    }

    public function host(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['host_school_id' => ['required', 'uuid', 'exists:schools,id']]);
        $this->selector->setHost($plcWorkshop, $this->user(), $data['host_school_id']);

        return response()->json(['data' => $this->selector->current($plcWorkshop->refresh())]);
    }

    public function store(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate([
            'schools' => ['required', 'array', 'min:1', 'max:200'], 'schools.*.id' => ['required', 'uuid'], 'schools.*.seats' => ['nullable', 'integer', 'min:1', 'max:5000'], 'schools.*.from_suggestion' => ['nullable', 'boolean'],
            'exception_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->selector->add($plcWorkshop, $this->user(), $data['schools'], $data['exception_reason'] ?? null)]);
    }

    public function seats(Request $request, PlcWorkshop $plcWorkshop, string $school): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['seats' => ['nullable', 'integer', 'min:1', 'max:5000']]);

        return response()->json(['data' => $this->selector->seats($plcWorkshop, $this->user(), $school, $data['seats'] ?? null)]);
    }

    public function destroy(PlcWorkshop $plcWorkshop, string $school): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->selector->remove($plcWorkshop, $this->user(), $school)]);
    }

    public function exclude(Request $request, PlcWorkshop $plcWorkshop, string $school): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['reason' => ['required', Rule::in(SchoolSelector::EXCLUSIONS)], 'note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->selector->exclude($plcWorkshop, $this->user(), $school, $data['reason'], $data['note'] ?? null)]);
    }
}
