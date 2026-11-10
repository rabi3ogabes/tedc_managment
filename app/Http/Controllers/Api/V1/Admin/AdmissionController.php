<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\TrainingGroup;
use App\Services\RegistrationService;
use App\Services\SeatAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admission: seat allocation per entity and the two-stage approval queues. */
class AdmissionController extends Controller
{
    public function __construct(private readonly SeatAllocationService $seats, private readonly RegistrationService $registrations) {}

    public function seats(TrainingGroup $group): JsonResponse
    {
        return response()->json(['data' => $this->seats->summary($group) + ['allocations' => $this->seats->allocations($group)->all()]]);
    }

    public function updateSeats(Request $request, TrainingGroup $group): JsonResponse
    {
        $d = $request->validate([
            'allocations' => ['present', 'array', 'max:200'], 'allocations.*.entity_type' => ['required', Rule::in(['school', 'department', 'school_group', 'job_group'])], 'allocations.*.entity_id' => ['required', 'uuid'],
            'allocations.*.seats' => ['required', 'integer', 'min:0', 'max:5000'], 'allocations.*.release_at' => ['nullable', 'date'], 'allocations.*.priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        $this->seats->replace($group, $d['allocations']);

        return $this->seats($group);
    }

    /** Registrations waiting for the current user: as the direct manager, or (staff) for the centre. */
    public function queue(Request $request, string $stage): JsonResponse
    {
        abort_unless(in_array($stage, ['manager', 'center'], true), 404);
        $user = $this->user();
        $q = Registration::with(['program:id,code,title_ar,title_en,registration_closes_at', 'employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en', 'trainingGroup:id,code'])
            ->when($stage === 'manager', fn ($q) => $q->where('status', Registration::STATUS_PENDING_MANAGER)->where('manager_id', $user->id))
            ->when($stage === 'center', fn ($q) => $q->where('status', Registration::STATUS_PENDING))
            ->orderByDesc('priority_score')->orderBy('created_at')->limit(300)->get();

        return response()->json(['data' => $q->map(fn ($r) => [
            'id' => $r->id, 'status' => $r->status, 'program' => ['id' => $r->program_id, 'code' => $r->program->code, 'title' => $r->program->translate('title'), 'closes_at' => $r->program->registration_closes_at?->toIso8601String()],
            'group' => $r->trainingGroup?->code, 'employee' => $r->employee->user?->displayName(), 'school' => $r->employee->school?->translate('name'), 'priority_score' => $r->priority_score, 'priority_explanation' => $r->priority_explanation,
            'manager_note' => $r->manager_note, 'created_at' => $r->created_at?->toIso8601String(),
        ])->all()]);
    }

    public function managerDecision(Request $request, Registration $registration): JsonResponse
    {
        abort_unless($registration->manager_id === $this->user()->id || $this->user()->hasPermission('registrations.manage'), 403);
        $d = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $r = $this->registrations->managerDecision($registration, $this->user(), $d['decision'], $d['note'] ?? null);

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status]]);
    }
}
