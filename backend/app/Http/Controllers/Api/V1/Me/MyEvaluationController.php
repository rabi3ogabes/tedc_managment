<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\EvaluationAssignment;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** "My evaluations": the forms waiting for the signed-in person, and answering them with evidence. */
class MyEvaluationController extends MeController
{
    public function __construct(private readonly EvaluationService $evaluations) {}

    public function index(): JsonResponse
    {
        $rows = EvaluationAssignment::with('form:id,kind,title_ar,title_en,settings', 'program:id,code,title_ar,title_en', 'respondent:id')->where('respondent_user_id', $this->user()->id)->whereIn('status', ['pending', 'submitted'])->orderByRaw("case status when 'pending' then 0 else 1 end")->latest()->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($a) => $this->summary($a))->values()]);
    }

    public function show(EvaluationAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->respondent_user_id === $this->user()->id, 404);
        $assignment->load('form', 'program:id,code,title_ar,title_en', 'response');

        return response()->json(['data' => $this->summary($assignment) + ['questions' => $assignment->form->questions, 'answers' => $assignment->response?->answers, 'evidence_allowed' => (bool) ($assignment->form->settings['evidence'] ?? false), 'max_files' => (int) ($assignment->form->settings['max_files'] ?? 3)]]);
    }

    public function submit(Request $request, EvaluationAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->respondent_user_id === $this->user()->id, 404);
        $data = $request->validate(['answers' => ['required', 'array'], 'evidence' => ['sometimes', 'array']]);
        // Multipart: answers[...] fields and evidence[questionId][] files or links.
        $evidence = [];
        foreach ((array) ($data['evidence'] ?? []) as $qid => $items) {
            $evidence[$qid] = array_filter((array) $items, 'is_string');
        }
        foreach ($request->allFiles()['evidence'] ?? [] as $qid => $files) {
            $evidence[$qid] = array_merge($evidence[$qid] ?? [], array_filter((array) $files, fn ($f) => $f instanceof UploadedFile));
        }
        $r = $this->evaluations->submit($assignment, $data['answers'], array_filter($evidence), $this->user());

        return response()->json(['data' => ['submitted' => true, 'score' => $r->score]], 201);
    }

    private function summary(EvaluationAssignment $a): array
    {
        return ['id' => $a->id, 'kind' => $a->form->kind, 'title_ar' => $a->form->title_ar, 'title_en' => $a->form->title_en, 'program' => $a->program?->translate('title'), 'respondent_type' => $a->respondent_type, 'status' => $a->status, 'due_at' => $a->due_at?->toIso8601String()];
    }
}
