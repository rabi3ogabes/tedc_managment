<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Services\Plc\WorkshopAttendance;
use App\Services\Plc\WorkshopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** PLC Phase 5: the live roster and code of a session, corrections, locking and the workshop's attendance report. */
class WorkshopAttendanceController extends Controller
{
    public function __construct(private readonly WorkshopAttendance $attendance, private readonly WorkshopService $workshops) {}

    public function roster(PlcWorkshop $plcWorkshop, ProgramSession $plcSession): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->attendance->roster($plcWorkshop, $plcSession, $this->user())]);
    }

    public function code(PlcWorkshop $plcWorkshop, ProgramSession $plcSession): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->attendance->issueCode($plcWorkshop, $plcSession, $this->user())]);
    }

    public function revoke(Request $request, PlcWorkshop $plcWorkshop, ProgramSession $plcSession): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->attendance->revoke($plcWorkshop, $plcSession, $this->user(), $d['reason'] ?? null)]);
    }

    public function correct(Request $request, PlcWorkshop $plcWorkshop, ProgramSession $plcSession): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['registration_id' => ['required', 'uuid'], 'status' => ['required', Rule::in(WorkshopAttendance::CORRECTABLE)], 'minutes' => ['nullable', 'integer', 'min:0', 'max:1440'], 'reason' => ['nullable', 'string', 'max:500']]);
        $r = Registration::where('program_id', $plcWorkshop->program_id)->findOrFail($d['registration_id']);
        $this->attendance->correct($plcWorkshop, $plcSession, $r, $this->user(), $d['status'], $d['minutes'] ?? null, $d['reason'] ?? null);

        return response()->json(['data' => $this->attendance->roster($plcWorkshop, $plcSession->fresh(), $this->user())]);
    }

    public function lock(PlcWorkshop $plcWorkshop, ProgramSession $plcSession): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $written = $this->attendance->lock($plcWorkshop, $plcSession, $this->user());

        return response()->json(['data' => ['absent_written' => $written] + $this->attendance->roster($plcWorkshop, $plcSession->fresh(), $this->user())]);
    }

    public function unlock(Request $request, PlcWorkshop $plcWorkshop, ProgramSession $plcSession): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->attendance->unlock($plcWorkshop, $plcSession, $this->user(), $d['reason'] ?? null);

        return response()->json(['data' => $this->attendance->roster($plcWorkshop, $plcSession->fresh(), $this->user())]);
    }

    public function report(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->attendance->report($plcWorkshop)]);
    }

    public function export(PlcWorkshop $plcWorkshop): Response
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response($this->attendance->csv($plcWorkshop), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="attendance-'.$plcWorkshop->program->code.'.csv"']);
    }
}
