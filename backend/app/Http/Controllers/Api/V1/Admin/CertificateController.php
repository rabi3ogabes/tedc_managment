<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Program;
use App\Models\Registration;
use App\Services\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CertificateController extends Controller
{
    public function __construct(private readonly CertificateService $certificates) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $schoolId = $this->schoolScope();

        return CertificateResource::collection(Certificate::with(['program', 'employee.user'])
            ->when($schoolId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('school_id', $schoolId)))
            ->when($request->query('program_id'), fn ($q, $id) => $q->where('program_id', $id))
            ->when($request->query('q'), fn ($q, $term) => $q->where('certificate_no', 'like', "%{$term}%"))
            ->latest('issued_at')
            ->paginate($this->perPage($request, 25)));
    }

    public function requirements(Registration $registration): JsonResponse
    {
        return response()->json(['data' => $this->certificates->requirements($registration)]);
    }

    public function issue(Registration $registration): CertificateResource
    {
        return new CertificateResource($this->certificates->issue($registration, $this->user())->load(['program', 'employee.user']));
    }

    /**
     * Issues certificates for every eligible participant of a program.
     */
    public function issueForProgram(Program $program): JsonResponse
    {
        $issued = 0;
        $blocked = [];

        $program->registrations()->with(['employee.user', 'program'])
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->whereIn('certificate_status', ['pending', 'eligible', 'blocked'])
            ->get()
            ->each(function (Registration $registration) use (&$issued, &$blocked) {
                try {
                    $this->certificates->issue($registration, $this->user());
                    $issued++;
                } catch (BusinessRuleException $e) {
                    $blocked[] = [
                        'registration_id' => $registration->id,
                        'employee' => $registration->employee->user->displayName(),
                        'failed' => collect($e->details['checks'] ?? [])->where('passed', false)->pluck('label')->values(),
                    ];
                }
            });

        return response()->json(['data' => ['issued' => $issued, 'blocked' => $blocked]]);
    }

    public function revoke(Request $request, Certificate $certificate): CertificateResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new CertificateResource($this->certificates->revoke($certificate, $data['reason']));
    }
}
