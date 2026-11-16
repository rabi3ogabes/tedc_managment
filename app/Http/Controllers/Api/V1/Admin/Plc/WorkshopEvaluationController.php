<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcWorkshop;
use App\Services\Plc\WorkshopEvaluation;
use App\Services\Plc\WorkshopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** PLC Phase 6: the evaluation plan of a workshop, sending, reminders, the close, and the export. */
class WorkshopEvaluationController extends Controller
{
    public function __construct(private readonly WorkshopEvaluation $evaluation, private readonly WorkshopService $workshops) {}

    public function show(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->evaluation->tracking($plcWorkshop, $this->user())]);
    }

    /** Sends the chosen instruments now (by instrument, or one trainer / session job key). */
    public function send(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['instruments' => ['required', 'array', 'min:1', 'max:50'], 'instruments.*' => ['string', 'max:80']]);
        $sent = $this->evaluation->dispatch($plcWorkshop, $this->user(), $data['instruments'], true);

        return response()->json(['data' => ['sent' => $sent] + $this->evaluation->tracking($plcWorkshop->refresh(), $this->user())]);
    }

    public function remind(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['instrument' => ['nullable', 'string', 'in:workshop,trainer,session,community']]);
        $n = $this->evaluation->remind($plcWorkshop, $data['instrument'] ?? null, $this->user());

        return response()->json(['data' => ['reminded' => $n] + $this->evaluation->tracking($plcWorkshop->refresh(), $this->user())]);
    }

    public function close(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000'], 'exception' => ['sometimes', 'boolean']]);
        $w = $this->evaluation->close($plcWorkshop, $this->user(), $data['reason'] ?? null, (bool) ($data['exception'] ?? false));

        return response()->json(['data' => $this->evaluation->tracking($w, $this->user())]);
    }

    public function export(PlcWorkshop $plcWorkshop): Response
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response($this->evaluation->csv($plcWorkshop), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="plc-evaluation-'.$plcWorkshop->program->code.'.csv"']);
    }
}
