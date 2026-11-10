<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Services\Plc\WorkshopSchedule;
use App\Services\Plc\WorkshopService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** PLC Phase 4: the workshop's sessions, the conflicts panel, alternatives, the scheduled transition and the calendar. */
class WorkshopScheduleController extends Controller
{
    public function __construct(private readonly WorkshopSchedule $schedule, private readonly WorkshopService $workshops) {}

    public function index(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->schedule->overview($plcWorkshop, $this->user())]);
    }

    public function store(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $s = $this->schedule->plan($plcWorkshop, $this->user(), $this->validated($request));

        return response()->json(['data' => ['id' => $s->id] + $this->schedule->overview($plcWorkshop->fresh(), $this->user())], 201);
    }

    public function update(Request $request, PlcWorkshop $plcWorkshop, ProgramSession $plcSession): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        abort_unless($plcSession->program_id === $plcWorkshop->program_id, 404);
        $this->schedule->plan($plcWorkshop, $this->user(), $this->validated($request), $plcSession);

        return response()->json(['data' => $this->schedule->overview($plcWorkshop->fresh(), $this->user())]);
    }

    public function destroy(Request $request, PlcWorkshop $plcWorkshop, ProgramSession $plcSession): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->schedule->remove($plcWorkshop, $this->user(), $plcSession, $d['reason'] ?? null);

        return response()->json(['data' => $this->schedule->overview($plcWorkshop->fresh(), $this->user())]);
    }

    public function alternatives(Request $request, PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());
        $d = $request->validate(['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'training_room_id' => ['nullable', 'uuid'], 'trainer_ids' => ['nullable', 'array'], 'trainer_ids.*' => ['uuid'], 'except' => ['nullable', 'uuid']]);
        $except = ! empty($d['except']) ? ProgramSession::where('program_id', $plcWorkshop->program_id)->find($d['except']) : null;

        return response()->json(['data' => $this->schedule->alternatives($plcWorkshop, $d, $except)]);
    }

    public function schedule(PlcWorkshop $plcWorkshop): JsonResponse
    {
        $this->workshops->assertVisible($plcWorkshop, $this->user());

        return response()->json(['data' => $this->schedule->schedule($plcWorkshop, $this->user())]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $d = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'school_id' => ['nullable', 'uuid'], 'region' => ['nullable', 'string', 'max:120'], 'trainer_id' => ['nullable', 'uuid'], 'mode' => ['nullable', Rule::in(WorkshopSchedule::MODES)], 'state' => ['nullable', 'string', 'max:24']]);
        $from = CarbonImmutable::parse($d['from'] ?? today()->startOfMonth());
        $to = CarbonImmutable::parse($d['to'] ?? $from->endOfMonth());
        abort_if($from->diffInDays($to) > 62, 422, __('messages.plc.calendar_span'));

        return response()->json(['data' => $this->schedule->calendar($this->user(), $from, $to, $d, $this->workshops)]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title_ar' => ['nullable', 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'mode' => ['nullable', Rule::in(WorkshopSchedule::MODES)],
            'training_room_id' => ['nullable', 'uuid', 'exists:training_rooms,id'], 'location_text' => ['nullable', 'string', 'max:200'],
            'online_url' => ['nullable', 'url', 'max:500'], 'online_platform' => ['nullable', 'string', 'max:40'], 'online_passcode' => ['nullable', 'string', 'max:64'],
            'checkin_window_minutes' => ['nullable', 'integer', 'min:0', 'max:240'], 'checkout_window_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'trainer_ids' => ['nullable', 'array', 'max:6'], 'trainer_ids.*' => ['uuid'], 'reason' => ['nullable', 'string', 'max:500'],
            'force' => ['nullable', 'boolean'], 'force_reason' => ['nullable', 'string', 'max:500'], 'calendar_approval_reason' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
