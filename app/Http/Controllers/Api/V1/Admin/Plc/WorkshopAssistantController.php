<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcWorkshop;
use App\Services\Plc\WorkshopAssistant;
use App\Services\Plc\WorkshopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PLC Phase 8: the planning checklist and completion tracker, the explanations, the impact chain, and the opt-in feedback themes. */
class WorkshopAssistantController extends Controller
{
    public function __construct(private readonly WorkshopAssistant $assistant, private readonly WorkshopService $workshops) {}

    public function show(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->assistant->checklist($plcWorkshop, $this->user())]);
    }

    public function themes(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['fresh' => ['sometimes', 'boolean'], 'lang' => ['sometimes', 'in:ar,en']]);

        return response()->json(['data' => $this->assistant->themes($plcWorkshop, $this->user(), $data['lang'] ?? app()->getLocale(), (bool) ($data['fresh'] ?? false))]);
    }
}
