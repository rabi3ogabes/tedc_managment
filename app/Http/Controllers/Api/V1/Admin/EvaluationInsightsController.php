<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvaluationInterview;
use App\Models\Program;
use App\Models\SatisfactionAlert;
use App\Services\ComparativeAnalysis;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pre/post comparison, the interviews log and the satisfaction alerts log. */
class EvaluationInsightsController extends Controller
{
    public function comparative(Program $program, Request $request, ComparativeAnalysis $analysis): JsonResponse
    {
        $group = $request->validate(['group' => ['nullable', 'uuid']])['group'] ?? null;

        return response()->json(['data' => $analysis->forProgram($program->id, $group)]);
    }

    public function interviews(Program $program): JsonResponse
    {
        return response()->json(['data' => EvaluationInterview::with('interviewer:id,name,name_ar')->where('program_id', $program->id)->orderByDesc('held_at')->get()->map(fn ($i) => $i->toArray() + ['interviewer_name' => $i->interviewer?->displayName()])->all()]);
    }

    public function storeInterview(Request $request, Program $program, EvaluationService $evaluations): JsonResponse
    {
        $d = $this->validated($request);
        $attachments = $this->attachments($request, $evaluations, $program->id);
        $row = EvaluationInterview::create($d + ['program_id' => $program->id, 'interviewer_id' => $this->user()->id, 'attachments' => $attachments ?: null]);

        return response()->json(['data' => $row], 201);
    }

    public function updateInterview(Request $request, EvaluationInterview $interview, EvaluationService $evaluations): JsonResponse
    {
        $d = $this->validated($request, true);
        $new = $this->attachments($request, $evaluations, $interview->program_id);
        $interview->update($d + ($new ? ['attachments' => array_merge($interview->attachments ?? [], $new)] : []));

        return response()->json(['data' => $interview->fresh()]);
    }

    public function destroyInterview(EvaluationInterview $interview): JsonResponse
    {
        $interview->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function alerts(Program $program): JsonResponse
    {
        return response()->json(['data' => SatisfactionAlert::where('program_id', $program->id)->latest()->get()->all()]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'group_id' => ['nullable', 'uuid', 'exists:training_groups,id'], 'interviewee_user_id' => ['nullable', 'uuid', 'exists:users,id'], 'interviewee_name' => ['nullable', 'string', 'max:200'],
            'held_at' => [$r, 'date'], 'method' => [$r, Rule::in(['in_person', 'online', 'phone'])], 'sentiment' => [$r, Rule::in(['positive', 'neutral', 'negative'])], 'summary' => ['nullable', 'string', 'max:5000'],
            'questions_answers' => ['nullable', 'array', 'max:50'], 'questions_answers.*.question' => ['required', 'string', 'max:500'], 'questions_answers.*.answer' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function attachments(Request $request, EvaluationService $evaluations, string $dir): array
    {
        $items = array_merge($request->file('files', []) ?: [], array_filter((array) $request->input('links', []), 'is_string'));

        return $items ? $evaluations->evidenceItems($items, "interviews/{$dir}", 10, 'files') : [];
    }
}
