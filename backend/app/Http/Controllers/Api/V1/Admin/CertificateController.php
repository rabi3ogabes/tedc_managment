<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Program;
use App\Models\Registration;
use App\Services\CertificateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CertificateController extends Controller
{
    public function __construct(private readonly CertificateService $certificates) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CertificateResource::collection($this->filtered($request)->with(['program', 'employee.user', 'sender'])
            ->latest('issued_at')
            ->paginate($this->perPage($request, 25)));
    }

    /**
     * E-mails certificates to their holders. Send to specific `ids`, or to everything that matches the
     * list filters with `all: true`. Already-sent, revoked and address-less certificates are skipped
     * (already-sent ones are sent again with `resend: true`).
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required_without:all', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            'all' => ['sometimes', 'boolean'],
            'resend' => ['sometimes', 'boolean'],
            'program_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:valid,revoked'],
            'sent' => ['nullable', 'in:yes,no'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = $this->filtered($request)->with(['program', 'employee.user'])
            ->when(! $request->boolean('all'), fn ($q) => $q->whereIn('id', $data['ids'] ?? []));
        $total = (clone $query)->count();
        abort_if($total > 500, 422, __('messages.certificate.too_many', ['max' => 500]));

        $result = ['sent' => 0, 'skipped' => [], 'failed' => []];
        $query->orderBy('issued_at')->get()->each(function (Certificate $certificate) use (&$result, $request) {
            $outcome = $this->certificates->send($certificate, $this->user(), $request->boolean('resend'));
            $row = ['id' => $certificate->id, 'certificate_no' => $certificate->certificate_no, 'employee' => $certificate->employee->user?->displayName(), 'reason' => $outcome['reason'] ?? null];
            match ($outcome['status']) {
                'sent' => $result['sent']++,
                'skipped' => $result['skipped'][] = $row,
                default => $result['failed'][] = $row,
            };
        });

        return response()->json(['data' => $result + ['total' => $total]]);
    }

    /** List filters shared by the index and bulk send; school admins only ever see their own school. */
    private function filtered(Request $request)
    {
        return Certificate::query()
            ->tap(fn ($q) => $this->scope()->constrainThroughEmployee($q))
            ->when($request->input('program_id'), fn ($q, $id) => $q->where('program_id', $id))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('sent') === 'yes', fn ($q) => $q->whereNotNull('sent_at'))
            ->when($request->input('sent') === 'no', fn ($q) => $q->whereNull('sent_at'))
            ->when($request->input('from'), fn ($q, $d) => $q->where('issued_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($request->input('to'), fn ($q, $d) => $q->where('issued_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($request->input('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('certificate_no', 'like', "%{$term}%")
                ->orWhereHas('employee', fn ($e) => $e->where('employee_no', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('name_ar', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))));
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
