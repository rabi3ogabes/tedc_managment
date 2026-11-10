<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\EvaluationAssignment;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Services\EvaluationService;
use App\Services\Surveys\SurveyCollector;
use App\Services\Surveys\SurveyFiles;
use App\Services\Surveys\SurveyPrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * "My evaluations": the forms waiting for the signed-in person, and answering them. A form that lives on the survey
 * engine (Gate 5) is answered through the engine: autosaved draft, file answers, the same validation as the studio.
 */
class MyEvaluationController extends MeController
{
    public function __construct(private readonly EvaluationService $evaluations, private readonly SurveyCollector $collector) {}

    public function index(): JsonResponse
    {
        $rows = EvaluationAssignment::with('form:id,kind,title_ar,title_en,settings,survey_id', 'program:id,code,title_ar,title_en', 'respondent:id', 'trainer:id,name_ar,name_en', 'session:id,sequence,title_ar,title_en,starts_at')->where('respondent_user_id', $this->user()->id)->whereIn('status', ['pending', 'submitted'])->orderByRaw("case status when 'pending' then 0 else 1 end")->latest()->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($a) => $this->summary($a))->values()]);
    }

    public function show(EvaluationAssignment $assignment): JsonResponse
    {
        $this->own($assignment);
        $assignment->load('form', 'program:id,code,title_ar,title_en,start_date,end_date', 'response', 'survey.currentVersion', 'trainer:id,name_ar,name_en', 'session:id,sequence,title_ar,title_en,starts_at');
        $survey = $assignment->survey;
        if (! $survey || ! $survey->currentVersion) {
            return response()->json(['data' => $this->summary($assignment) + ['context' => $this->context($assignment)] + ['questions' => $assignment->form->questions, 'answers' => $assignment->response?->answers, 'evidence_allowed' => (bool) ($assignment->form->settings['evidence'] ?? false), 'max_files' => (int) ($assignment->form->settings['max_files'] ?? 3)]]);
        }
        $version = $survey->currentVersion;
        $submission = $this->collector->submissionFor($survey, $this->user(), $assignment->id);
        $draft = $assignment->status === 'pending' ? $this->collector->draftFor($survey, $this->user(), $assignment->id) : null;

        return response()->json(['data' => $this->summary($assignment) + ['context' => $this->context($assignment)] + [
            // The legacy shape, without file questions, keeps older clients answering; the engine block is for the new form.
            'questions' => array_values(array_filter($version->questions, fn ($q) => $q['type'] !== 'file_upload')),
            'answers' => $submission?->answers, 'evidence_allowed' => false, 'max_files' => 0,
            'engine' => [
                'survey_id' => $survey->id, 'version' => $version->number, 'questions' => $version->questions, 'settings' => $version->settings, 'privacy_mode' => $survey->privacy_mode,
                'is_open' => $assignment->status === 'pending' && $survey->isOpen(),
                'draft' => $draft ? ['answers' => $draft->draft_answers, 'page' => $draft->page, 'saved_at' => $draft->last_seen_at] : null,
                'submission' => $submission ? ['answers' => $submission->answers, 'submitted_at' => $submission->submitted_at] : null,
            ],
        ]]);
    }

    /** Autosave for an engine-backed form. */
    public function draft(Request $request, EvaluationAssignment $assignment): JsonResponse
    {
        $this->own($assignment);
        $survey = $assignment->survey ?: abort(404);
        abort_unless($assignment->status === 'pending', 422, __('messages.evaluation.submitted'));
        $data = $request->validate(['answers' => ['present', 'array'], 'page' => ['nullable', 'integer', 'min:0', 'max:500']]);
        $session = $this->collector->saveDraftFor($survey, $this->user(), $assignment->id, $data['answers'], (int) ($data['page'] ?? 0));

        return response()->json(['data' => ['saved_at' => $session->last_seen_at]]);
    }

    /** A file for a file-upload answer of an engine-backed form; bound to the answer on submit. */
    public function upload(Request $request, EvaluationAssignment $assignment, SurveyFiles $files): JsonResponse
    {
        $this->own($assignment);
        $survey = $assignment->survey ?: abort(404);
        abort_unless($assignment->status === 'pending' && $survey->isOpen(), 422, __('messages.surveys.not_open'));
        $data = $request->validate(['question_id' => ['required', 'string', 'max:40'], 'file' => ['required', 'file', 'max:25600']]);
        $question = collect($survey->currentVersion?->questions ?? [])->firstWhere('id', $data['question_id']);
        abort_unless($question, 422, __('messages.surveys.file_question'));
        $file = $files->upload($survey, SurveyPrivacy::contextKey($survey, $this->user(), $assignment->id), $question, $request->file('file'));

        return response()->json(['data' => $files->present($file)], 201);
    }

    public function submit(Request $request, EvaluationAssignment $assignment): JsonResponse
    {
        $this->own($assignment);
        $data = $request->validate(['answers' => ['required', 'array'], 'evidence' => ['sometimes', 'array'], 'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'], 'source' => ['nullable', 'in:web,mobile']]);
        // Multipart (legacy forms): answers[...] fields and evidence[questionId][] files or links.
        $evidence = [];
        foreach ((array) ($data['evidence'] ?? []) as $qid => $items) {
            $evidence[$qid] = array_filter((array) $items, 'is_string');
        }
        foreach ($request->allFiles()['evidence'] ?? [] as $qid => $files) {
            $evidence[$qid] = array_merge($evidence[$qid] ?? [], array_filter((array) $files, fn ($f) => $f instanceof UploadedFile));
        }
        $a = $this->evaluations->submit($assignment, $data['answers'], array_filter($evidence), $this->user(), $data['duration_seconds'] ?? null, $data['source'] ?? 'web');

        return response()->json(['data' => ['submitted' => true, 'score' => $a->score !== null ? (float) $a->score : null]], 201);
    }

    private function own(EvaluationAssignment $assignment): void
    {
        abort_unless($assignment->respondent_user_id === $this->user()->id, 404);
    }

    /**
     * What the old form asked first and Shaheen already knows — «أولاً: معلومات عامة»: the respondent's name and job title, the programme
     * and when it ran (the group's dates when there is one).
     *
     * @return array{name: string, job: ?string, program: ?string, from: ?string, to: ?string}
     */
    private function context(EvaluationAssignment $a): array
    {
        $user = $this->user();
        $en = app()->getLocale() === 'en';
        $trainer = Trainer::where('user_id', $user->id)->first(['title_ar', 'title_en']);
        $job = $trainer ? ($en ? ($trainer->title_en ?: $trainer->title_ar) : ($trainer->title_ar ?: $trainer->title_en)) : null;
        $job ??= $user->employee?->jobTitle?->translate('name');
        $group = $a->group_id ? TrainingGroup::find($a->group_id, ['id', 'start_date', 'end_date']) : null;
        $program = $a->program;

        return ['name' => $en ? ($user->name ?: $user->name_ar) : ($user->name_ar ?: $user->name), 'job' => $job, 'program' => $program?->translate('title'),
            'from' => ($group?->start_date ?? $program?->start_date)?->toDateString(), 'to' => ($group?->end_date ?? $program?->end_date)?->toDateString()];
    }

    private function summary(EvaluationAssignment $a): array
    {
        $target = $a->trainer ? ['kind' => 'trainer', 'name_ar' => $a->trainer->name_ar, 'name_en' => $a->trainer->name_en ?: $a->trainer->name_ar]
            : ($a->session ? ['kind' => 'session', 'name_ar' => $a->session->title_ar ?: 'الجلسة '.$a->session->sequence, 'name_en' => $a->session->title_en ?: 'Session '.$a->session->sequence, 'at' => $a->session->starts_at?->toIso8601String()] : null);

        return ['id' => $a->id, 'kind' => $a->form->kind, 'title_ar' => $a->form->title_ar, 'title_en' => $a->form->title_en, 'program' => $a->program?->translate('title'), 'respondent_type' => $a->respondent_type, 'status' => $a->status, 'due_at' => $a->due_at?->toIso8601String(), 'engine_backed' => (bool) $a->survey_id,
            'target' => $target, 'rule_key' => $a->rule_key];
    }
}
