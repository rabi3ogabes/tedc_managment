<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Registration;
use App\Services\Assessment\AttemptService;
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
                'open_attempt_id' => $tries->firstWhere('status', 'in_progress')?->id, 'best_score' => $tries->where('status', 'graded')->max('score_percent'), 'last_attempt_id' => $tries->sortByDesc('started_at')->first()?->id];
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

    public function answers(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->own($attempt);
        $d = $request->validate(['answers' => ['required', 'array', 'max:300']]);
        $a = $this->attempts->autosave($attempt, $d['answers']);

        return response()->json(['data' => ['saved' => count($a->answers ?? []), 'remaining_seconds' => $this->attempts->remaining($a)]]);
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
