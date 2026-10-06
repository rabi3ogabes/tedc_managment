<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExternalCompletion;
use App\Models\ExternalCourse;
use App\Services\Library\ExternalLearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Content providers (Coursera, edX, Udemy Business, LinkedIn Learning), their catalogue, and the review of completions on other platforms. */
class ExternalLearningController extends Controller
{
    public function __construct(private readonly ExternalLearningService $svc) {}

    public function settings(Request $request): JsonResponse
    {
        if ($request->isMethod('PUT')) {
            $rules = [];
            foreach (ExternalLearningService::PROVIDERS as $p) {
                $rules += [$p => ['sometimes', 'array'], "$p.enabled" => ['boolean'], "$p.driver" => [Rule::in(['rest', 'fake'])], "$p.base_url" => ['nullable', 'url', 'max:500'], "$p.api_key" => ['nullable', 'string', 'max:300'], "$p.catalogue_path" => ['nullable', 'string', 'max:200'], "$p.completions_path" => ['nullable', 'string', 'max:200']];
            }

            return response()->json(['data' => $this->svc->update($request->validate($rules), $this->user())]);
        }

        return response()->json(['data' => $this->svc->masked()]);
    }

    public function sync(): JsonResponse
    {
        return response()->json(['data' => $this->svc->sync()]);
    }

    public function catalogue(Request $request): JsonResponse
    {
        return response()->json(['data' => ExternalCourse::when($request->query('provider'), fn ($q, $p) => $q->where('provider', $p))->orderBy('title')->limit(500)->get()->all()]);
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
