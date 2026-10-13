<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Registration;
use App\Services\Assessment\AttemptService;
use App\Services\Assessment\OfflineExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The trainee's side of assessments: list, start, autosave, integrity events, submit and result. */
class MyAssessmentController extends MeController
{
    public function __construct(private readonly AttemptService $attempts) {}

    public function index(): JsonResponse
    {
        $employee = $this->employee();
        $regs = Registration::where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get();
        $rows = Assessment::where('status', 'published')->whereIn('program_id', $regs->pluck('program_id'))->with('program:id,code,title_ar,title_en')->orderBy('window_opens_at')->get();
        $mine = AssessmentAttempt::whereIn('registration_id', $regs->pluck('id'))->where('status', '!=', 'voided')->get()->groupBy('assessment_id');

        return response()->json(['data' => $rows->map(function ($a) use ($mine, $regs) {
            $reg = $regs->firstWhere('program_id', $a->program_id);
            $tries = ($mine[$a->id] ?? collect())->filter(fn ($x) => $x->registration_id === $reg?->id);
            $now = now();

            return ['id' => $a->id, 'kind' => $a->kind, 'title_ar' => $a->title_ar, 'title_en' => $a->title_en, 'program' => $a->program?->translate('title'), 'delivery' => $a->delivery, 'time_limit_minutes' => $a->time_limit_minutes, 'max_attempts' => $a->max_attempts, 'attempts_used' => $tries->count(),
                'window_opens_at' => $a->window_opens_at?->toIso8601String(), 'window_closes_at' => $a->window_closes_at?->toIso8601String(), 'open' => (! $a->window_opens_at || $a->window_opens_at->lte($now)) && (! $a->window_closes_at || $a->window_closes_at->gte($now)), 'requires_code' => $a->delivery === 'in_center',
                'open_attempt_id' => $tries->firstWhere('status', 'in_progress')?->id, 'best_score' => $tries->where('status', 'graded')->max('score_percent'), 'last_attempt_id' => $tries->sortByDesc('started_at')->first()?->id,
                'allow_offline' => (bool) $a->allow_offline && ! $a->adaptive && $a->delivery !== 'in_center', 'offline_window_hours' => (int) $a->offline_window_hours, 'adaptive' => (bool) $a->adaptive];
        })->values()->all()]);
    }

    public function start(Request $request, Assessment $assessment): JsonResponse
    {
        $d = $request->validate(['access_code' => ['nullable', 'string', 'max:20'], 'room_id' => ['nullable', 'uuid']]);
        $registration = Registration::where('program_id', $assessment->program_id)->where('employee_id', $this->employee()->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->when($assessment->group_id, fn ($q) => $q->where('training_group_id', $assessment->group_id))->firstOrFail();
        $attempt = $this->attempts->start($registration, $assessment, $d + ['ip' => $request->ip(), 'device' => $request->userAgent()]);

        return response()->json(['data' => $this->attempts->state($attempt->load('assessment'))]);
    }

    /** A single-use package for taking the assessment in the app without a connection (TYP-12). */
    public function offlineToken(Request $request, Assessment $assessment, OfflineExamService $offline): JsonResponse
    {
        $d = $request->validate(['device_id' => ['required', 'string', 'min:8', 'max:64']]);
        $registration = Registration::where('program_id', $assessment->program_id)->where('employee_id', $this->employee()->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->when($assessment->group_id, fn ($q) => $q->where('training_group_id', $assessment->group_id))->firstOrFail();

        return response()->json(['data' => $offline->issue($registration, $assessment, $d['device_id'])], 201);
    }

    /** The answers of an offline attempt, handed in when the phone is back online. */
    public function submitOffline(Request $request, OfflineExamService $offline): JsonResponse
    {
        $d = $request->validate([
            'token_id' => ['required', 'uuid'], 'nonce' => ['required', 'string', 'max:64'], 'signature' => ['required', 'string', 'max:64'], 'device_id' => ['required', 'string', 'max:64'],
            'answers' => ['present', 'array', 'max:500'], 'device_elapsed_seconds' => ['required', 'integer', 'min:0', 'max:604800'],
            'client_started_at' => ['nullable', 'date'], 'client_submitted_at' => ['nullable', 'date'],
        ]);
        $attempt = $offline->submit($this->user(), $d);

        return response()->json(['data' => $this->attempts->result($attempt->fresh('assessment'))], 201);
    }

    public function answers(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->own($attempt);
        $d = $request->validate(['answers' => ['required', 'array', 'max:300']]);
        $a = $this->attempts->autosave($attempt, $d['answers']);

        return response()->json(['data' => ['saved' => count($a->answers ?? []), 'remaining_seconds' => $this->attempts->remaining($a)]]);
    }

    /** Adaptive practice: the answer to the question on screen, and the next question with the reason it was chosen. */
    public function adaptiveAnswer(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->own($attempt);
        $d = $request->validate(['question_id' => ['required', 'string', 'max:64'], 'answer' => ['present']]);

        return response()->json(['data' => $this->attempts->adaptiveAnswer($attempt, $d['question_id'], $d['answer'])]);
    }

    public function event(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->own($attempt);

        return response()->json(['data' => $this->attempts->event($attempt->load('assessment'), $request->validate(['type' => ['required', 'string', 'max:30']])['type'])]);
    }

    public function snapshot(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->own($attempt);
        $this->attempts->snapshot($attempt->load('assessment'), $request->validate(['image' => ['required', 'string', 'max:600000']])['image']);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function submit(AssessmentAttempt $attempt): JsonResponse
    {
        $this->own($attempt);
        $this->attempts->submit($attempt);

        return response()->json(['data' => $this->attempts->result($attempt->fresh('assessment'))]);
    }

    public function result(AssessmentAttempt $attempt): JsonResponse
    {
        $this->own($attempt);
        $this->attempts->settle($attempt);

        return response()->json(['data' => $this->attempts->result($attempt->fresh('assessment'))]);
    }

    private function own(AssessmentAttempt $a): void
    {
        abort_unless($a->registration->employee_id === $this->employee()->id, 404);
    }
}
