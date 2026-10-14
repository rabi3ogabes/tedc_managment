<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProfileChangeRequest;
use App\Services\Account\AccountFields;
use App\Services\Account\AccountService;
use App\Services\Account\HrProfileRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Review queue for the data-change requests users send from "My account". */
class ProfileRequestController extends Controller
{
    public function __construct(private readonly AccountService $account) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $q = ProfileChangeRequest::with(['user.employee.school', 'user.employee.jobTitle', 'reviewer:id,name,name_ar'])
            ->when($status !== 'all', fn ($w) => $status === 'resolved' ? $w->whereIn('status', ['approved', 'rejected']) : $w->where('status', $status))
            ->when($request->query('q'), fn ($w, $v) => $w->whereHas('user', fn ($u) => $u->whereLike('name', "%{$v}%")->orWhereLike('name_ar', "%{$v}%")->orWhereLike('email', "%{$v}%")))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")->latest();

        return response()->json([
            'counts' => $this->counts(),
            'data' => $q->paginate(20)->through(fn (ProfileChangeRequest $r) => $this->row($r)),
        ]);
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->counts()]);
    }

    public function approve(Request $request, ProfileChangeRequest $changeRequest): JsonResponse
    {
        $data = $request->validate(['apply' => ['sometimes', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        $this->account->approve($changeRequest, $request->user(), (bool) ($data['apply'] ?? false), $data['note'] ?? null);

        return response()->json(['data' => $this->row($changeRequest->fresh(['user.employee.school', 'user.employee.jobTitle', 'reviewer']))]);
    }

    /** Sends a request to HR again: after it was given up, or without waiting for the next attempt. */
    public function resend(ProfileChangeRequest $changeRequest, HrProfileRouting $routing): JsonResponse
    {
        $routing->resend($changeRequest);

        return response()->json(['data' => $this->row($changeRequest->fresh(['user.employee.school', 'user.employee.jobTitle', 'reviewer']))]);
    }

    public function reject(Request $request, ProfileChangeRequest $changeRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->account->reject($changeRequest, $request->user(), $data['note'] ?? null);

        return response()->json(['data' => $this->row($changeRequest->fresh(['user.employee.school', 'user.employee.jobTitle', 'reviewer']))]);
    }

    private function counts(): array
    {
        return ['pending' => ProfileChangeRequest::where('status', 'pending')->count(), 'resolved' => ProfileChangeRequest::whereIn('status', ['approved', 'rejected'])->count()];
    }

    private function row(ProfileChangeRequest $r): array
    {
        $f = AccountFields::field($r->field);
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $e = $r->user?->employee;
        $routes = $r->hr_status !== null || ($r->status === 'pending' && app(HrProfileRouting::class)->masters($r->field));   // approving it sends it to HR

        return [
            'id' => $r->id, 'field' => $r->field, 'label' => $f['label'][$locale] ?? $r->field, 'type' => $f['type'] ?? 'text', 'kind' => $r->kind, 'status' => $r->status,
            'can_apply' => (bool) ($f && $f['auto'] && $f['column']) && ! $routes,
            'routes_to_hr' => $routes, 'hr_status' => $r->hr_status, 'hr_reference' => $r->hr_reference, 'hr_reason' => $r->hr_reason, 'hr_attempts' => $r->hr_attempts, 'hr_last_error' => $r->hr_last_error,
            'hr_sent_at' => $r->hr_sent_at?->toIso8601String(), 'hr_resolved_at' => $r->hr_resolved_at?->toIso8601String(),
            'current_value' => $r->current_value, 'requested_value' => $f && $f['options'] ? ($f['options'][$r->requested_value][$locale] ?? $r->requested_value) : $r->requested_value,
            'note' => $r->note, 'applied' => $r->applied, 'review_note' => $r->review_note,
            'user' => ['id' => $r->user_id, 'name' => $r->user?->displayName(), 'email' => $r->user?->email, 'employee_id' => $e?->id, 'employee_no' => $e?->employee_no,
                'school' => $e?->school?->translate('name'), 'job_title' => $e?->jobTitle?->translate('name')],
            'by' => $r->reviewer?->displayName(), 'created_at' => $r->created_at->toIso8601String(), 'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
