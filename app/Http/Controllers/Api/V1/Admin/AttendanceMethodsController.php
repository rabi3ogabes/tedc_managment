<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Services\AttendanceExport;
use App\Services\AttendanceMethodsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Kiosk signature, staff scanning, the personal QR and trainer attendance. */
class AttendanceMethodsController extends Controller
{
    public function __construct(private readonly AttendanceMethodsService $methods) {}

    public function kiosk(ProgramSession $session): JsonResponse
    {
        $session->loadMissing('program');

        return response()->json(['data' => ['session' => ['id' => $session->id, 'title_ar' => $session->title_ar, 'title_en' => $session->title_en, 'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String()], 'roster' => $this->methods->roster($session, $this->user())]]);
    }

    public function sign(Request $request, ProgramSession $session): JsonResponse
    {
        $d = $request->validate(['registration_id' => ['required', 'uuid'], 'direction' => ['required', Rule::in(['in', 'out'])], 'signature' => ['required', 'string', 'max:420000']]);
        $a = $this->methods->sign($session, Registration::findOrFail($d['registration_id']), $d['direction'], $d['signature'], $this->user());

        return response()->json(['data' => ['id' => $a->id, 'method' => $a->method, 'status' => $a->status, 'check_in_at' => $a->check_in_at?->toIso8601String(), 'check_out_at' => $a->check_out_at?->toIso8601String(), 'minutes' => $a->minutes_attended]]);
    }

    public function staffScan(Request $request, ProgramSession $session): JsonResponse
    {
        return response()->json(['data' => $this->methods->staffScan($session, $request->validate(['payload' => ['required', 'string', 'max:200']])['payload'], $this->user())]);
    }

    public function trainerRows(ProgramSession $session): JsonResponse
    {
        return response()->json(['data' => $this->methods->trainerRows($session)]);
    }

    public function markTrainer(Request $request, ProgramSession $session): JsonResponse
    {
        $d = $request->validate(['trainer_id' => ['required', 'uuid', 'exists:trainers,id'], 'status' => ['required', Rule::in(['present', 'absent'])], 'notes' => ['nullable', 'string', 'max:500']]);
        $this->methods->markTrainer($session, Trainer::findOrFail($d['trainer_id']), $d['status'], $this->user(), $d['notes'] ?? null);

        return $this->trainerRows($session);
    }

    public function exportSession(Request $request, ProgramSession $session, AttendanceExport $export): Response
    {
        $format = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])]])['format'];

        return $this->file($format === 'xlsx' ? $export->sessionXlsx($session) : $export->sessionPdf($session), $format, 'attendance-'.$session->id);
    }

    public function exportGroup(Request $request, TrainingGroup $group, AttendanceExport $export): Response
    {
        $format = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])]])['format'];

        return $this->file($format === 'xlsx' ? $export->groupXlsx($group) : $export->groupPdf($group), $format, 'attendance-'.$group->code);
    }

    public function blankSheet(ProgramSession $session, AttendanceExport $export): Response
    {
        return $this->file($export->blankSheet($session), 'pdf', 'sheet-'.$session->id);
    }

    private function file(string $bytes, string $format, string $name): Response
    {
        return response($bytes, 200, ['Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.{$format}\""]);
    }

    // Me --------------------------------------------------------------------------------------------------------

    public function myQr(): JsonResponse
    {
        $user = $this->user();
        if ($user->trainer) {
            return response()->json(['data' => $this->methods->personalQr('t', $user->trainer->id) + ['kind' => 'trainer']]);
        }
        abort_unless($user->employee, 404);

        return response()->json(['data' => $this->methods->personalQr('e', $user->employee->id) + ['kind' => 'trainee']]);
    }

    public function trainerScan(Request $request): JsonResponse
    {
        $r = $this->methods->trainerScan($this->user(), $request->validate(['payload' => ['required', 'string', 'max:200']])['payload']);

        return response()->json(['data' => ['action' => $r['action'], 'session' => $r['session']->only(['id', 'title_ar', 'title_en', 'starts_at', 'ends_at'])]]);
    }
}
