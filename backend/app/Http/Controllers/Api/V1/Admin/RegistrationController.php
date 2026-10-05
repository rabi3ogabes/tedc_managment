<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\RegistrationResource;
use App\Models\Employee;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Services\BulkImportService;
use App\Services\RecommendationEngine;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RegistrationController extends Controller
{
    public function __construct(private readonly RegistrationService $registrations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $scope = $this->scope();

        $query = Registration::with(['employee.user', 'employee.school', 'employee.jobTitle', 'program'])
            ->tap(fn ($q) => $scope->constrainThroughEmployee($q))
            ->when($request->query('program_id'), fn ($q, $id) => $q->where('program_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('source'), fn ($q, $s) => $q->where('source', $s))
            ->when($request->query('certificate_status'), fn ($q, $s) => $q->where('certificate_status', $s))
            ->when($request->query('q'), fn ($q, $term) => $q->whereHas('employee', fn ($e) => $e->whereLike('employee_no', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->whereLike('name', "%{$term}%")->orWhereLike('name_ar', "%{$term}%"))))
            ->latest();

        return RegistrationResource::collection($query->paginate($this->perPage($request, 25)));
    }

    public function show(Registration $registration): RegistrationResource
    {
        $this->authorizeSchool($registration->employee);

        return new RegistrationResource($registration->load(['employee.user', 'employee.school', 'program', 'certificate']));
    }

    /**
     * Direct nomination by the training center or a school administrator.
     */
    public function nominate(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['uuid', 'exists:employees,id'],
            'justification' => ['nullable', 'string', 'max:1000'],
            'override' => ['sometimes', 'boolean'],
        ]);

        $user = $this->user();
        $isCenter = $this->isCenterStaff($user);
        abort_unless($isCenter || $user->hasRole(Role::SCHOOL_ADMIN), 403);

        $results = [];
        foreach (Employee::whereIn('id', $data['employee_ids'])->get() as $employee) {
            try {
                if (! $isCenter) {
                    $this->authorizeSchool($employee);
                }
                $registration = $this->registrations->nominate(
                    $program,
                    $employee,
                    $user,
                    $isCenter ? 'training_center' : 'school_admin',
                    $data['justification'] ?? null,
                    $isCenter && ($data['override'] ?? false),
                );
                $results[] = ['employee_id' => $employee->id, 'ok' => true, 'status' => $registration->status, 'registration_id' => $registration->id];
            } catch (BusinessRuleException $e) {
                $results[] = ['employee_id' => $employee->id, 'ok' => false, 'message' => $e->getMessage(), 'details' => $e->details];
            }
        }

        return response()->json(['data' => $results], 201);
    }

    public function updateStatus(Request $request, Registration $registration): RegistrationResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Registration::STATUS_APPROVED, Registration::STATUS_REJECTED, Registration::STATUS_CANCELLED, Registration::STATUS_WAITLISTED, Registration::STATUS_COMPLETED])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->registrations->transition($registration, $data['status'], $this->user(), $data['notes'] ?? null);

        return $this->show($registration->refresh());
    }

    public function bulkStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            'status' => ['required', Rule::in([Registration::STATUS_APPROVED, Registration::STATUS_REJECTED, Registration::STATUS_CANCELLED])],
        ]);

        $done = 0;
        $failed = [];
        foreach (Registration::whereIn('id', $data['ids'])->get() as $registration) {
            try {
                $this->registrations->transition($registration, $data['status'], $this->user());
                $done++;
            } catch (BusinessRuleException $e) {
                $failed[] = ['id' => $registration->id, 'message' => $e->getMessage()];
            }
        }

        return response()->json(['data' => ['updated' => $done, 'failed' => $failed]]);
    }

    public function import(Request $request, Program $program, BulkImportService $import): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'override' => ['sometimes', 'boolean'],
        ]);

        $report = $import->importRegistrations(
            $program,
            $request->file('file')->getRealPath(),
            $this->user(),
            $this->isCenterStaff() && $request->boolean('override'),
        );

        return response()->json(['data' => $report]);
    }

    public function template(BulkImportService $import): BinaryFileResponse
    {
        return response()->download($import->template(), 'registrations-template.xlsx')->deleteFileAfterSend();
    }

    /**
     * Eligible employees ranked by skill gap — helps admins target nominations.
     */
    public function candidates(Request $request, Program $program, RecommendationEngine $engine): JsonResponse
    {
        $candidates = $engine->candidatesForProgram($program, $request->query('school_id'), 50, $this->scope());

        return response()->json(['data' => $candidates->map(fn ($c) => [
            'employee' => new EmployeeResource($c['employee']),
            'skill_gap' => $c['gap'],
        ])]);
    }

    private function authorizeSchool(Employee $employee): void
    {
        if (! $this->scope()->allowsEmployee($employee)) {
            throw new BusinessRuleException(__('messages.registration.outside_school'), 'outside_school');
        }
    }
}
