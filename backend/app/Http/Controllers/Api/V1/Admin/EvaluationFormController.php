<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Services\EvaluationService;
use App\Services\EvaluationSettings;
use App\Services\NeedsSurveys\SurveySchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The evaluation form registry (with the approval flow of Phase 03) and the evaluation settings. */
class EvaluationFormController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluations, private readonly EvaluationSettings $settings) {}

    public function index(): JsonResponse
    {
        $this->evaluations->ensureDefaults();

        return response()->json(['data' => EvaluationForm::orderByDesc('is_default')->orderBy('kind')->orderBy('title_ar')->get()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request, true);

        return response()->json(['data' => EvaluationForm::create($d + ['questions' => SurveySchema::normalize($d['questions']), 'created_by' => $this->user()->id, 'approval_status' => 'draft'])], 201);
    }

    public function update(Request $request, EvaluationForm $form): JsonResponse
    {
        if ($form->is_system) {
            throw new BusinessRuleException(__('messages.evaluation.system_form'), 'system_form');
        }
        $d = $this->validated($request, false);
        // A change to an approved form is a new version that needs approving again.
        $changed = isset($d['questions']) && $d['questions'] !== $form->questions;
        $form->update(collect($d)->except('questions')->all() + (isset($d['questions']) ? ['questions' => SurveySchema::normalize($d['questions'])] : [])
            + ($changed && $form->approval_status === 'approved' ? ['version' => $form->version + 1, 'approval_status' => 'draft', 'approved_by' => null, 'approved_at' => null] : []));

        return response()->json(['data' => $form->fresh()]);
    }

    public function destroy(EvaluationForm $form): JsonResponse
    {
        if ($form->is_system || $form->is_default || EvaluationAssignment::where('form_id', $form->id)->exists()) {
            throw new BusinessRuleException(__('messages.evaluation.form_in_use'), 'form_in_use');
        }
        $form->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function submitApproval(EvaluationForm $form): JsonResponse
    {
        $form->update(['approval_status' => 'pending']);

        return response()->json(['data' => $form->fresh()]);
    }

    public function decide(Request $request, EvaluationForm $form, string $decision): JsonResponse
    {
        abort_unless(in_array($decision, ['approve', 'return'], true), 404);
        $note = $request->validate(['note' => [$decision === 'return' ? 'required' : 'nullable', 'string', 'max:500']])['note'] ?? null;
        $form->update($decision === 'approve' ? ['approval_status' => 'approved', 'approved_by' => $this->user()->id, 'approved_at' => now(), 'approval_note' => $note] : ['approval_status' => 'returned', 'approval_note' => $note]);

        return response()->json(['data' => $form->fresh()]);
    }

    public function settings(string $part = 'all'): JsonResponse
    {
        $all = $this->settings->all();

        return response()->json(['data' => $part === 'all' ? $all : ($all[$part] ?? abort(404))]);
    }

    public function updateSettings(Request $request, string $part = 'all'): JsonResponse
    {
        $d = $request->validate([
            'impact' => ['sometimes', 'array'], 'impact.trainee_days' => ['sometimes', 'integer', 'min:1', 'max:365'], 'impact.manager_days' => ['sometimes', 'integer', 'min:1', 'max:365'], 'impact.optional_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'impact.reminder_after_days' => ['sometimes', 'integer', 'min:1', 'max:90'], 'impact.expiry_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'alerts' => ['sometimes', 'array'], 'alerts.is_active' => ['sometimes', 'boolean'], 'alerts.min_response_rate' => ['sometimes', 'numeric', 'min:1', 'max:100'], 'alerts.threshold' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'alerts.recipients' => ['sometimes', 'array'], 'alerts.recipients.*' => [Rule::in(['center_leadership', 'program_coordinator', 'planning_head', 'center_admin'])],
            'classification' => ['sometimes', 'array'], 'classification.*' => ['numeric', 'min:0', 'max:100'], 'anonymous_min_responses' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $allowed = ['all' => ['impact', 'alerts', 'classification', 'anonymous_min_responses'], 'impact' => ['impact'], 'alerts' => ['alerts']][$part] ?? [];
        $next = $this->settings->update(array_intersect_key($d, array_flip($allowed)), $this->user());

        return response()->json(['data' => $part === 'all' ? $next : $next[$part]]);
    }

    private function validated(Request $request, bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return $request->validate([
            'kind' => [$r, Rule::in(EvaluationService::FORM_KINDS)], 'title_ar' => [$r, 'string', 'max:200'], 'title_en' => [$r, 'string', 'max:200'], 'questions' => [$r, 'array', 'min:1', 'max:150'],
            'settings' => ['nullable', 'array'], 'settings.anonymous' => ['sometimes', 'boolean'], 'settings.evidence' => ['sometimes', 'boolean'], 'settings.max_files' => ['sometimes', 'integer', 'min:1', 'max:10'], 'settings.required_for_certificate' => ['sometimes', 'boolean'],
        ]);
    }
}
