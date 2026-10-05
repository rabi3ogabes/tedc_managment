<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Program;
use App\Services\Assessment\AssessmentAnalytics;
use App\Services\Assessment\AssessmentService;
use App\Services\Assessment\AttemptService;
use App\Services\Assessment\KnowledgeService;
use App\Services\Assessment\QuestionTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Assessments: builder, publishing, access codes, invigilation, grading, release, regrade and analytics. */
class AssessmentController extends Controller
{
    public function __construct(private readonly AssessmentService $builder, private readonly AttemptService $attempts) {}

    public function index(Program $program): JsonResponse
    {
        return response()->json(['data' => Assessment::withCount('attempts')->with('sections')->where('program_id', $program->id)->orderBy('kind')->orderBy('created_at')->get()->all()]);
    }

    public function store(Request $request, Program $program): JsonResponse
    {
        $a = Assessment::create($request->validate($this->rules(true)) + ['program_id' => $program->id, 'status' => 'draft']);
        $request->has('sections') && $this->builder->replaceSections($a, $request->validate(['sections' => ['array', 'max:30']])['sections']);

        return response()->json(['data' => $a->fresh('sections')], 201);
    }

    public function show(Assessment $assessment): JsonResponse
    {
        return response()->json(['data' => $assessment->load('sections') + [], 'validation' => $this->builder->validate($assessment)]);
    }

    public function update(Request $request, Assessment $assessment): JsonResponse
    {
        $assessment->update($request->validate($this->rules(false)));

        return response()->json(['data' => $assessment->fresh('sections')]);
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        abort_if($assessment->attempts()->exists(), 422, __('messages.assessment.has_attempts'));
        $assessment->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function sections(Request $request, Assessment $assessment): JsonResponse
    {
        $d = $request->validate(['sections' => ['present', 'array', 'max:30'], 'sections.*.title' => ['nullable', 'string', 'max:200'], 'sections.*.selection' => ['required', Rule::in(['fixed', 'random'])], 'sections.*.bank_id' => ['nullable', 'uuid', 'exists:question_banks,id'],
            'sections.*.category_ids' => ['nullable', 'array'], 'sections.*.category_ids.*' => ['uuid'], 'sections.*.difficulty_mix' => ['nullable', 'array'], 'sections.*.difficulty_mix.*' => ['integer', 'min:0', 'max:500'], 'sections.*.count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'sections.*.points_per_question' => ['nullable', 'numeric', 'min:0', 'max:1000'], 'sections.*.question_ids' => ['nullable', 'array', 'max:500'], 'sections.*.question_ids.*' => ['uuid']]);

        return response()->json(['data' => $this->builder->replaceSections($assessment, $d['sections'])]);
    }

    public function validateAssessment(Assessment $assessment): JsonResponse
    {
        return response()->json(['data' => $this->builder->validate($assessment)]);
    }

    public function publish(Assessment $assessment): JsonResponse
    {
        return response()->json(['data' => $this->builder->publish($assessment)]);
    }

    public function accessCode(Request $request, Assessment $assessment): JsonResponse
    {
        $d = $request->validate(['group_id' => ['nullable', 'uuid'], 'room_id' => ['nullable', 'uuid', 'exists:training_rooms,id'], 'valid_from' => ['nullable', 'date'], 'valid_to' => ['nullable', 'date', 'after:valid_from']]);
        abort_if($assessment->access_code_mode === 'none', 422, __('messages.assessment.no_code_mode'));
        $r = $this->attempts->createCode($assessment, $d, $this->user());

        return response()->json(['data' => ['code' => $r['code'], 'rotating' => $r['rotating'], 'id' => $r['row']->id]], 201);
    }

    /** Invigilation: the rotating code, who is taking the exam, and the integrity flags. */
    public function live(Assessment $assessment): JsonResponse
    {
        $rows = AssessmentAttempt::with('registration.employee.user:id,name,name_ar')->where('assessment_id', $assessment->id)->whereIn('status', ['in_progress', 'grading', 'graded'])->latest('started_at')->limit(300)->get();

        return response()->json(['data' => [
            'rotating_code' => $this->attempts->rotatingCode($assessment),
            'attempts' => $rows->map(fn ($a) => ['id' => $a->id, 'employee' => $a->registration->employee->user?->displayName(), 'status' => $a->status, 'started_at' => $a->started_at->toIso8601String(), 'remaining_seconds' => $this->attempts->remaining($a), 'delivery' => $a->delivery, 'flags' => $a->integrity['flags'] ?? [], 'events' => $a->integrity['events'] ?? [], 'answered' => count($a->answers ?? []), 'total' => count($a->questions)])->all(),
        ]]);
    }

    public function extend(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->attempts->extend($attempt, (int) $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:240']])['minutes']);

        return response()->json(['data' => ['extra_minutes' => $attempt->fresh()->extra_minutes]]);
    }

    public function void(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->attempts->void($attempt, $request->validate(['reason' => ['required', 'string', 'max:300']])['reason']);

        return response()->json(['data' => ['status' => 'voided']]);
    }

    public function grading(Assessment $assessment): JsonResponse
    {
        $rows = AssessmentAttempt::with('registration.employee.user:id,name,name_ar')->where('assessment_id', $assessment->id)->where('status', 'grading')->orderBy('submitted_at')->get();

        return response()->json(['data' => $rows->map(function ($a) {
            $manual = collect($a->questions)->filter(fn ($q) => QuestionTypes::get($q['type'])->grade($q['payload'], null)['manual']);

            return ['attempt_id' => $a->id, 'employee' => $a->registration->employee->user?->displayName(), 'submitted_at' => $a->submitted_at?->toIso8601String(), 'items' => $manual->map(fn ($q) => ['question_id' => $q['id'], 'stem_ar' => $q['stem_ar'], 'stem_en' => $q['stem_en'], 'max_points' => $q['points'], 'rubric' => $q['payload']['rubric'] ?? [], 'answer' => ($a->answers ?? [])[$q['id']] ?? null])->values()->all()];
        })->all()]);
    }

    public function grade(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $d = $request->validate(['grades' => ['required', 'array', 'min:1', 'max:200'], 'grades.*.question_id' => ['required', 'string'], 'grades.*.points' => ['required', 'numeric', 'min:0'], 'grades.*.comment' => ['nullable', 'string', 'max:1000'], 'feedback' => ['nullable', 'string', 'max:3000']]);
        $a = $this->attempts->grade($attempt, $d['grades'], $d['feedback'] ?? null, $this->user());

        return response()->json(['data' => ['id' => $a->id, 'status' => $a->status, 'score_percent' => $a->score_percent, 'passed' => $a->passed]]);
    }

    public function release(Assessment $assessment): JsonResponse
    {
        $assessment->update(['released_at' => now()]);

        return response()->json(['data' => ['released_at' => $assessment->released_at->toIso8601String()]]);
    }

    public function regrade(Request $request, Assessment $assessment): JsonResponse
    {
        $d = $request->validate(['replace' => ['nullable', 'array'], 'replace.*' => ['uuid']]);

        return response()->json(['data' => ['regraded' => $this->attempts->regrade($assessment, $d['replace'] ?? [], $this->user())]]);
    }

    public function analytics(Assessment $assessment, AssessmentAnalytics $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->forAssessment($assessment)]);
    }

    public function knowledgeGain(Program $program, KnowledgeService $knowledge): JsonResponse
    {
        $rows = $knowledge->gain($program->id);
        $with = collect($rows)->whereNotNull('gain');

        return response()->json(['data' => ['rows' => $rows, 'average_gain_percent' => $with->whereNotNull('gain_percent')->avg('gain_percent') !== null ? round($with->whereNotNull('gain_percent')->avg('gain_percent'), 1) : null, 'target_percent' => 35]]);
    }

    private function rules(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return [
            'kind' => [$r, Rule::in(['final', 'quiz', 'diagnostic', 'pre_test', 'post_test', 'comprehensive', 'practice'])], 'title_ar' => [$r, 'string', 'max:200'], 'title_en' => [$r, 'string', 'max:200'], 'instructions_ar' => ['nullable', 'string', 'max:5000'], 'instructions_en' => ['nullable', 'string', 'max:5000'],
            'group_id' => ['nullable', 'uuid', 'exists:training_groups,id'], 'lesson_id' => ['nullable', 'uuid', 'exists:course_lessons,id'], 'delivery' => ['sometimes', Rule::in(['remote', 'in_center', 'either'])], 'access_code_mode' => ['sometimes', Rule::in(['none', 'static', 'rotating'])],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'], 'window_opens_at' => ['nullable', 'date'], 'window_closes_at' => ['nullable', 'date'], 'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:50'], 'attempt_cooldown_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'pass_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'weight_in_course' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'shuffle_questions' => ['sometimes', 'boolean'], 'shuffle_options' => ['sometimes', 'boolean'],
            'feedback_mode' => ['sometimes', Rule::in(['immediate', 'after_submit', 'after_close', 'never'])], 'show_score' => ['sometimes', 'boolean'], 'show_correct_answers' => ['sometimes', 'boolean'], 'require_restudy_on_fail' => ['sometimes', 'boolean'],
            'proctoring' => ['nullable', 'array'], 'proctoring.enabled' => ['sometimes', 'boolean'], 'proctoring.fullscreen' => ['sometimes', 'boolean'], 'proctoring.tab_switch_limit' => ['nullable', 'integer', 'min:0', 'max:100'], 'proctoring.fullscreen_exit_limit' => ['nullable', 'integer', 'min:0', 'max:100'],
            'proctoring.paste_limit' => ['nullable', 'integer', 'min:0', 'max:100'], 'proctoring.action' => ['sometimes', Rule::in(['flag', 'auto_submit'])], 'proctoring.snapshots' => ['sometimes', 'boolean'], 'proctoring.face_check' => ['sometimes', 'boolean'],
        ];
    }
}
