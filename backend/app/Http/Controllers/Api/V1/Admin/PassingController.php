<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\PassException;
use App\Models\PassingPolicy;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TrainingGroup;
use App\Services\CertificateService;
use App\Services\FileStorage;
use App\Services\PassingPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Passing policies (global, program, group), the simulation, exceptions and in-session participation marks. */
class PassingController extends Controller
{
    public function __construct(private readonly PassingPolicyService $passing, private readonly CertificateService $certificates) {}

    public function show(Request $request, string $scope, ?string $id = null): JsonResponse
    {
        $this->checkScope($scope, $id);
        $row = $this->passing->stored($scope, $scope === 'global' ? null : $id);
        $inherited = $row ? null : ($scope === 'group' ? $this->passing->stored('program', TrainingGroup::find($id)?->program_id) : null) ?? ($scope !== 'global' ? $this->passing->stored('global', null) : null);

        return response()->json(['data' => ['policy' => $row, 'inherited_from' => $inherited ? $inherited->scope : null, 'effective' => $row ?? $inherited]]);
    }

    public function save(Request $request, string $scope, ?string $id = null): JsonResponse
    {
        $this->checkScope($scope, $id);
        $d = $this->validated($request, $scope, $id);
        $row = PassingPolicy::updateOrCreate(['scope' => $scope, 'scope_id' => $scope === 'global' ? null : $id], $d + ['updated_by' => $this->user()->id]);
        $this->recomputeAffected($scope, $id);

        return response()->json(['data' => $row->fresh()]);
    }

    public function destroy(string $scope, ?string $id = null): JsonResponse
    {
        $this->checkScope($scope, $id);
        PassingPolicy::where('scope', $scope)->where('scope_id', $scope === 'global' ? null : $id)->delete();
        $this->recomputeAffected($scope, $id);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** What would happen to the current participants under a draft policy, without saving it. */
    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate(['program_id' => ['required', 'uuid', 'exists:programs,id'], 'group_id' => ['nullable', 'uuid', 'exists:training_groups,id']]);
        $draft = new PassingPolicy($this->validated($request, 'program', $d['program_id']));
        $rows = Registration::with('employee.user:id,name,name_ar', 'program')->where('program_id', $d['program_id'])->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->when($d['group_id'] ?? null, fn ($q, $g) => $q->where('training_group_id', $g))->limit(300)->get()
            ->map(function (Registration $r) use ($draft) {
                $e = $this->passing->evaluate($r, $draft);

                return ['registration_id' => $r->id, 'employee' => $r->employee->user?->displayName(), 'passed' => $e['passed'], 'via' => $e['via'], 'weighted_score' => $e['weighted_score'],
                    'criteria' => array_map(fn ($c) => ['key' => $c['key'], 'value' => $c['value'], 'applicable' => $c['applicable'], 'met' => $c['met'], 'min' => $c['min'], 'weight' => $c['weight']], $e['criteria']),
                    'now' => $r->pass_status];
            })->values();

        return response()->json(['data' => ['rows' => $rows, 'would_pass' => $rows->where('passed', true)->count(), 'total' => $rows->count()]]);
    }

    public function status(Registration $registration): JsonResponse
    {
        $registration->loadMissing('program', 'employee.user');
        $e = $this->passing->evaluate($registration);
        $hours = $this->passing->hours($registration, $e['policy']);

        return response()->json(['data' => [
            'pass_status' => $registration->pass_status, 'passed_via' => $registration->passed_via, 'weighted_score' => $e['weighted_score'], 'policy' => $e['policy'], 'criteria' => $e['criteria'], 'hours' => $hours,
            'exceptions' => PassException::with('grantor:id,name,name_ar')->where('registration_id', $registration->id)->latest('granted_at')->get(),
            'certificates' => $registration->certificates()->get(['id', 'type', 'certificate_no', 'status', 'hours', 'issued_at']),
        ]]);
    }

    public function grantException(Request $request, Registration $registration, FileStorage $storage): JsonResponse
    {
        $d = $request->validate(['criterion' => ['required', Rule::in(PassingPolicyService::KEYS)], 'reason' => ['required', 'string', 'min:5', 'max:2000'], 'attachment' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx']]);
        $file = $request->file('attachment');
        $path = $storage->upload($file, 'exceptions', $registration->id);
        $e = $this->passing->grantException($registration, $d['criterion'], $d['reason'], $path, mb_substr($file->getClientOriginalName(), 0, 200), $this->user());
        $this->certificates->refreshStatus($registration->refresh());

        return response()->json(['data' => $e], 201);
    }

    public function revokeException(PassException $exception): JsonResponse
    {
        $this->passing->revokeException($exception, $this->user());
        $this->certificates->refreshStatus($exception->registration()->first());

        return response()->json(['data' => $exception->fresh()]);
    }

    public function exceptionFile(PassException $exception, FileStorage $storage): JsonResponse
    {
        abort_unless($exception->attachment_path, 404);

        return response()->json(['data' => ['url' => $storage->temporaryUrl('exceptions', $exception->attachment_path), 'name' => $exception->attachment_name]]);
    }

    /** The trainer's quick toggle on the session roster: who took part. */
    public function participation(Request $request, ProgramSession $session): JsonResponse
    {
        $d = $request->validate(['marks' => ['required', 'array', 'min:1', 'max:300'], 'marks.*.registration_id' => ['required', 'uuid'], 'marks.*.participated' => ['required', 'boolean']]);
        $n = 0;
        foreach ($d['marks'] as $m) {
            $a = Attendance::where('program_session_id', $session->id)->where('registration_id', $m['registration_id'])->first();
            if (! $a) {
                continue;
            }
            $a->update(['participated' => $m['participated']]);
            $n++;
            $this->certificates->refreshStatus(Registration::find($m['registration_id']));
        }

        return response()->json(['data' => ['updated' => $n]]);
    }

    private function checkScope(string $scope, ?string $id): void
    {
        abort_unless(in_array($scope, ['global', 'program', 'group'], true), 404);
        if ($scope === 'program') {
            abort_unless($id && Program::whereKey($id)->exists(), 404);
        }
        if ($scope === 'group') {
            abort_unless($id && TrainingGroup::whereKey($id)->exists(), 404);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $scope, ?string $id): array
    {
        $d = $request->validate([
            'mode' => ['required', Rule::in(['all_required', 'weighted'])],
            'criteria' => ['required', 'array', 'min:1', 'max:8'], 'criteria.*.key' => ['required', Rule::in(PassingPolicyService::KEYS), 'distinct'],
            'criteria.*.required' => ['sometimes', 'boolean'], 'criteria.*.min' => ['nullable', 'numeric', 'min:0', 'max:100'], 'criteria.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pass_threshold' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'assessment_ids' => ['nullable', 'array', 'max:30'], 'assessment_ids.*.id' => ['required', 'uuid'], 'assessment_ids.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'participation_rules' => ['nullable', 'array'], 'participation_rules.lessons' => ['sometimes', 'boolean'], 'participation_rules.session_marks' => ['sometimes', 'boolean'],
            'allow_test_out' => ['sometimes', 'boolean'], 'test_out_assessment_id' => ['nullable', 'uuid', 'exists:assessments,id'],
            'hours_mode' => ['sometimes', Rule::in(['total', 'actual'])], 'certificate_types' => ['sometimes', Rule::in(['attendance', 'pass', 'both'])],
            'attendance_certificate_min' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'survey_required_for_download' => ['sometimes', 'boolean'],
            'task_approval' => ['sometimes', Rule::in(['trainer', 'trainer_then_supervisor', 'auto'])],
            'certificate_templates' => ['nullable', 'array'], 'certificate_templates.attendance' => ['nullable', 'uuid'], 'certificate_templates.pass' => ['nullable', 'uuid'],
        ]);

        if ($d['mode'] === 'weighted') {
            $sum = array_sum(array_map(fn ($c) => (float) ($c['weight'] ?? 0), $d['criteria']));
            if (abs($sum - 100) > 0.01) {
                throw ValidationException::withMessages(['criteria' => __('messages.passing.weights_sum', ['sum' => round($sum, 2)])]);
            }
        }
        if (($d['allow_test_out'] ?? false) && empty($d['test_out_assessment_id'])) {
            throw ValidationException::withMessages(['test_out_assessment_id' => __('messages.passing.test_out_needs_assessment')]);
        }
        if (! empty($d['test_out_assessment_id']) && $scope !== 'global') {
            $programId = $scope === 'program' ? $id : TrainingGroup::find($id)?->program_id;
            if (! Assessment::whereKey($d['test_out_assessment_id'])->where('program_id', $programId)->exists()) {
                throw new BusinessRuleException(__('messages.passing.test_out_wrong_program'), 'test_out_wrong_program');
            }
        }

        return $d;
    }

    /** A changed policy changes who passes: recompute the registrations it covers. */
    private function recomputeAffected(string $scope, ?string $id): void
    {
        Registration::query()->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->when($scope === 'program', fn ($q) => $q->where('program_id', $id))->when($scope === 'group', fn ($q) => $q->where('training_group_id', $id))
            ->limit(2000)->get()->each(fn ($r) => $this->certificates->refreshStatus($r));
    }
}
