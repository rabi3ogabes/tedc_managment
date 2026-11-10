<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\GroupTrainer;
use App\Models\PlcNomination;
use App\Models\PlcWorkshop;
use App\Services\Plc\WorkshopPeople;
use App\Services\Plc\WorkshopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The people steps of a workshop: trainee nominations and trainers. */
class WorkshopPeopleController extends Controller
{
    public function __construct(private readonly WorkshopPeople $people, private readonly WorkshopService $workshops) {}

    public function eligible(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'school_id' => ['nullable', 'uuid'], 'job_title_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $this->people->eligible($plcWorkshop, $this->user(), array_filter($f))]);
    }

    public function nominations(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->people->nominations($plcWorkshop)]);
    }

    public function nominate(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['employee_ids' => ['required', 'array', 'min:1', 'max:500'], 'employee_ids.*' => ['uuid'], 'note' => ['nullable', 'string', 'max:500'], 'force' => ['nullable', 'boolean'], 'force_reason' => ['nullable', 'string', 'max:1000']]);
        $result = $this->people->nominate($plcWorkshop, $this->user(), $d['employee_ids'], $d['note'] ?? null, (bool) ($d['force'] ?? false), $d['force_reason'] ?? null);

        return response()->json(['data' => $result + ['nominations' => $this->people->nominations($plcWorkshop)]]);
    }

    public function decide(Request $request, PlcWorkshop $plcWorkshop, PlcNomination $plcNomination): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['to' => ['required', Rule::in(PlcNomination::STATUSES)], 'reason' => ['nullable', 'string', 'max:1000'], 'force' => ['nullable', 'boolean']]);
        $this->people->transition($plcWorkshop, $this->user(), $plcNomination, $d['to'], $d['reason'] ?? null, (bool) ($d['force'] ?? false));

        return response()->json(['data' => $this->people->nominations($plcWorkshop)]);
    }

    public function materialise(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        abort_unless($this->user()->hasPermission('plc.manage'), 403);

        return response()->json(['data' => $this->people->materialise($plcWorkshop, $this->user()) + ['nominations' => $this->people->nominations($plcWorkshop)]]);
    }

    public function trainers(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->people->trainerRows($plcWorkshop)]);
    }

    public function suggestTrainers(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'limit' => ['nullable', 'integer', 'min:1', 'max:60']]);

        return response()->json(['data' => $this->people->suggestTrainers($plcWorkshop, $d['q'] ?? null, (int) ($d['limit'] ?? 20))]);
    }

    public function propose(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['trainer_id' => ['required', 'uuid'], 'role' => ['required', Rule::in(['lead', 'co'])], 'hours' => ['nullable', 'numeric', 'min:0', 'max:500'], 'force' => ['nullable', 'boolean'], 'force_reason' => ['nullable', 'string', 'max:1000']]);
        $this->people->propose($plcWorkshop, $this->user(), $d['trainer_id'], $d['role'], isset($d['hours']) ? (float) $d['hours'] : null, (bool) ($d['force'] ?? false), $d['force_reason'] ?? null);

        return response()->json(['data' => $this->people->trainerRows($plcWorkshop)], 201);
    }

    public function confirm(Request $request, PlcWorkshop $plcWorkshop, GroupTrainer $plcAssignment): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->people->confirm($plcWorkshop, $this->user(), $plcAssignment, $d['note'] ?? null);

        return response()->json(['data' => $this->people->trainerRows($plcWorkshop)]);
    }

    public function replace(Request $request, PlcWorkshop $plcWorkshop, GroupTrainer $plcAssignment): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['trainer_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000'], 'force' => ['nullable', 'boolean']]);
        $this->people->replace($plcWorkshop, $this->user(), $plcAssignment, $d['trainer_id'], $d['reason'], (bool) ($d['force'] ?? false));

        return response()->json(['data' => $this->people->trainerRows($plcWorkshop)]);
    }

    public function withdraw(Request $request, PlcWorkshop $plcWorkshop, GroupTrainer $plcAssignment): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $this->people->withdraw($plcWorkshop, $this->user(), $plcAssignment, $d['reason'] ?? null);

        return response()->json(['data' => $this->people->trainerRows($plcWorkshop)]);
    }
}
