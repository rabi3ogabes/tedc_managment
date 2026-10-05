<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\WithdrawalReason;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Withdrawal: the trainee's request, the manager / supervisor queues, the policy and the reasons. */
class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function withdraw(Request $request, Registration $registration): JsonResponse
    {
        abort_unless($registration->employee_id === $this->user()->employee?->id, 404);
        $d = $request->validate(['reason_code' => ['nullable', 'string', 'max:40'], 'reason_text' => ['nullable', 'string', 'max:1000'], 'attachments' => ['sometimes', 'array', 'max:5'], 'attachments.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png']]);
        $r = $this->withdrawals->withdraw($registration, $this->user(), $d['reason_code'] ?? null, $d['reason_text'] ?? null, $request->file('attachments', []));

        return response()->json(['data' => ['mode' => $r['mode'], 'status' => $r['registration']->status, 'request' => isset($r['request']) ? $this->present($r['request']) : null]]);
    }

    public function mine(): JsonResponse
    {
        $rows = WithdrawalRequest::with('registration.program:id,code,title_ar,title_en')->where('requested_by', $this->user()->id)->latest()->get();

        return response()->json(['data' => $rows->map(fn ($w) => $this->present($w))->all()]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $rows = WithdrawalRequest::with(['registration.program:id,code,title_ar,title_en', 'registration.employee.user:id,name,name_ar'])->where('status', $request->query('status', 'pending'))
            ->when($request->query('stage'), fn ($q, $s) => $q->where('stage', $s))
            ->when(! $user->hasPermission('withdrawals.decide'), fn ($q) => $q->where('stage', 'manager')->where('manager_id', $user->id))->latest()->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($w) => $this->present($w))->all()]);
    }

    public function decision(Request $request, WithdrawalRequest $withdrawal): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->present($this->withdrawals->decide($withdrawal, $this->user(), $d['decision'], $d['note'] ?? null))]);
    }

    public function policy(Request $request): JsonResponse
    {
        if ($request->isMethod('put')) {
            $d = $request->validate(['min_days_before_start' => ['sometimes', 'integer', 'between:0,365'], 'allow_after_start' => ['sometimes', 'boolean'], 'late_counts_in_reports' => ['sometimes', 'boolean']]);

            return response()->json(['data' => $this->withdrawals->savePolicy($d)]);
        }

        return response()->json(['data' => $this->withdrawals->policy()]);
    }

    public function reasons(): JsonResponse
    {
        return response()->json(['data' => WithdrawalReason::orderBy('sort_order')->get()->all()]);
    }

    public function activeReasons(): JsonResponse
    {
        return response()->json(['data' => WithdrawalReason::where('is_active', true)->orderBy('sort_order')->get(['code', 'label_ar', 'label_en', 'requires_attachment'])->all()]);
    }

    public function saveReason(Request $request, ?WithdrawalReason $reason = null): JsonResponse
    {
        $d = $request->validate(['code' => [$reason ? 'sometimes' : 'required', 'alpha_dash', 'max:40', Rule::unique('withdrawal_reasons', 'code')->ignore($reason?->id)], 'label_ar' => [$reason ? 'sometimes' : 'required', 'string', 'max:200'], 'label_en' => [$reason ? 'sometimes' : 'required', 'string', 'max:200'], 'requires_attachment' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);
        $reason = $reason ? tap($reason)->update($d) : WithdrawalReason::create($d);

        return response()->json(['data' => $reason], $reason->wasRecentlyCreated ? 201 : 200);
    }

    private function present(WithdrawalRequest $w): array
    {
        $w->loadMissing(['registration.program', 'registration.employee.user']);

        return [
            'id' => $w->id, 'registration_id' => $w->registration_id, 'program' => $w->registration?->program?->translate('title'), 'employee' => $w->registration?->employee?->user?->displayName(), 'reason_code' => $w->reason_code, 'reason_text' => $w->reason_text,
            'timing' => $w->timing, 'stage' => $w->stage, 'status' => $w->status, 'is_late' => $w->is_late, 'attachments' => collect($w->attachments ?? [])->map(fn ($a) => ['name' => $a['name']])->all(),
            'manager_decision' => $w->manager_decision, 'manager_note' => $w->manager_note, 'supervisor_decision' => $w->supervisor_decision, 'supervisor_note' => $w->supervisor_note, 'created_at' => $w->created_at?->toIso8601String(),
        ];
    }
}
