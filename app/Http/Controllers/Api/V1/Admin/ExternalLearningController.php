<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExternalCompletion;
use App\Models\ExternalCourse;
use App\Services\Library\ExternalLearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The catalogue of the content providers (Coursera, Open edX, Udemy Business, LinkedIn Learning) turned into programmes, and the review of completions on other platforms. */
class ExternalLearningController extends Controller
{
    public function __construct(private readonly ExternalLearningService $svc) {}

    public function sync(): JsonResponse
    {
        return response()->json(['data' => $this->svc->sync(trigger: 'manual', by: $this->user())]);
    }

    public function createProgram(ExternalCourse $course): JsonResponse
    {
        return response()->json(['data' => $this->svc->programFromCourse($course, $this->user())], 201);
    }

    public function completions(Request $request): JsonResponse
    {
        $rows = ExternalCompletion::with('registration.employee.user:id,name,name_ar', 'registration.program:id,code,title_ar,title_en')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($c) => $c->toArray() + ['employee_name' => $c->registration->employee->user?->displayName(), 'program' => $c->registration->program->translate('title')])->all()]);
    }

    public function review(Request $request, ExternalCompletion $completion): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->svc->review($completion, $d['decision'], $d['note'] ?? null, $this->user())]);
    }
}
