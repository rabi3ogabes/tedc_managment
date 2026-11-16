<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbsenceAlert;
use App\Models\AbsenceExcuse;
use App\Models\Attendance;
use App\Models\AttendanceLeave;
use App\Models\Registration;
use App\Services\AbsenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Absence alerts, excuses and leaves. */
class AbsenceController extends Controller
{
    public function __construct(private readonly AbsenceService $absence) {}

    public function alerts(Request $request): JsonResponse
    {
        $user = $this->user();
        $rows = AbsenceAlert::with(['registration.program:id,code,title_ar,title_en,coordinator_id', 'registration.employee.user:id,name,name_ar', 'registration.trainingGroup:id,supervisor_id'])
            ->when($request->query('program_id'), fn ($q, $v) => $q->whereHas('registration', fn ($r) => $r->where('program_id', $v)))->latest()->limit(300)->get()
            ->filter(fn ($a) => $user->hasPermission('attendance.manage') && ($user->hasPermission('programs.manage') || in_array($user->id, array_filter([$a->registration->program->coordinator_id, $a->registration->trainingGroup?->supervisor_id]), true) || $user->hasPermission('registrations.view')));

        return response()->json(['data' => $rows->values()->map(fn ($a) => ['id' => $a->id, 'level' => $a->level, 'absence_percent' => $a->absence_percent, 'supervisor_note' => $a->supervisor_note, 'notified_at' => $a->notified_at?->toIso8601String(), 'program' => $a->registration->program->translate('title'), 'employee' => $a->registration->employee->user?->displayName(), 'registration_id' => $a->registration_id, 'created_at' => $a->created_at?->toIso8601String()])->all()]);
    }

    public function updateAlert(Request $request, AbsenceAlert $alert): JsonResponse
    {
        $d = $request->validate(['supervisor_note' => ['nullable', 'string', 'max:1000'], 'resend' => ['sometimes', 'boolean']]);
        $alert->update(['supervisor_note' => $d['supervisor_note'] ?? $alert->supervisor_note]);
        if ($d['resend'] ?? false) {
            $this->absence->announce($alert->fresh(), $alert->registration);
        }

        return response()->json(['data' => ['id' => $alert->id, 'supervisor_note' => $alert->fresh()->supervisor_note]]);
    }

    public function submitExcuse(Request $request, Registration $registration): JsonResponse
    {
        $d = $request->validate(['session_id' => ['nullable', 'uuid'], 'from_date' => ['nullable', 'date'], 'to_date' => ['nullable', 'date', 'after_or_equal:from_date'], 'reason_code' => ['required', Rule::in(['sick_leave', 'bereavement', 'work_assignment', 'other'])], 'reason_text' => ['nullable', 'string', 'max:1000'], 'attachments' => ['sometimes', 'array', 'max:5'], 'attachments.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png']]);
        $excuse = $this->absence->submitExcuse($registration->load(['employee.user', 'program']), $this->user(), collect($d)->except('attachments')->all(), $request->file('attachments', []));

        return response()->json(['data' => $this->presentExcuse($excuse)], 201);
    }

    public function myExcuses(): JsonResponse
    {
        $employee = $this->user()->employee;

        return response()->json(['data' => AbsenceExcuse::with('registration.program:id,title_ar,title_en')->where('employee_id', $employee?->id)->latest()->get()->map(fn ($e) => $this->presentExcuse($e))->all()]);
    }

    public function excuses(Request $request): JsonResponse
    {
        $user = $this->user();
        $rows = AbsenceExcuse::with(['registration.program:id,title_ar,title_en', 'employee.user:id,name,name_ar'])->where('status', $request->query('status', 'pending'))
            ->when(! $user->hasPermission('excuses.decide'), fn ($q) => $q->where('manager_id', $user->id))->latest()->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($e) => $this->presentExcuse($e))->all()]);
    }

    public function decideExcuse(Request $request, AbsenceExcuse $excuse): JsonResponse
    {
        $user = $this->user();
        abort_unless($excuse->manager_id === $user->id || $user->hasPermission('excuses.decide'), 403);
        $d = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->presentExcuse($this->absence->decideExcuse($excuse, $user, $d['decision'], $d['note'] ?? null))]);
    }

    public function storeLeave(Request $request, Attendance $attendance): JsonResponse
    {
        $d = $request->validate(['type' => ['required', Rule::in(['late_arrival', 'early_leave', 'temporary'])], 'minutes' => ['nullable', 'integer', 'min:1', 'max:1000'], 'from_time' => ['nullable', 'date_format:H:i'], 'to_time' => ['nullable', 'date_format:H:i'], 'reason' => ['nullable', 'string', 'max:500'], 'attachments' => ['sometimes', 'array', 'max:5'], 'attachments.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png']]);
        $leave = $this->absence->recordLeave($attendance, $this->user(), collect($d)->except('attachments')->all(), $request->file('attachments', []));

        return response()->json(['data' => ['id' => $leave->id, 'type' => $leave->type, 'minutes' => $leave->minutes]], 201);
    }

    public function destroyLeave(AttendanceLeave $leave): JsonResponse
    {
        $this->absence->deleteLeave($leave);

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function presentExcuse(AbsenceExcuse $e): array
    {
        $e->loadMissing(['registration.program', 'employee.user']);

        return ['id' => $e->id, 'registration_id' => $e->registration_id, 'session_id' => $e->session_id, 'from_date' => $e->from_date?->toDateString(), 'to_date' => $e->to_date?->toDateString(), 'reason_code' => $e->reason_code, 'reason_text' => $e->reason_text, 'status' => $e->status, 'decision_note' => $e->decision_note,
            'program' => $e->registration?->program?->translate('title'), 'employee' => $e->employee?->user?->displayName(), 'attachments' => collect($e->attachments ?? [])->map(fn ($a) => ['name' => $a['name']])->all(), 'created_at' => $e->created_at?->toIso8601String()];
    }
}
