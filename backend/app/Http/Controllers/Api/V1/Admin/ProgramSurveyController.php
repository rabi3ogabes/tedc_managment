<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Services\Notifications\ProgramSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The program survey: open / close it, schedule the automatic opening, and notify the trainees. */
class ProgramSurveyController extends Controller
{
    public function __construct(private readonly ProgramSurvey $survey) {}

    public function show(Program $program): JsonResponse
    {
        return $this->state($program);
    }

    public function update(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['always', 'manual', 'auto'])], 'auto_hours' => ['nullable', 'integer', 'min:0', 'max:720']]);
        $this->survey->configure($program, $data['mode'], $data['auto_hours'] ?? null);

        return $this->state($program);
    }

    public function open(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['notify' => ['sometimes', 'boolean']]);
        $this->survey->open($program, $request->user(), $data['notify'] ?? true);

        return $this->state($program);
    }

    public function close(Program $program): JsonResponse
    {
        $this->survey->close($program);

        return $this->state($program);
    }

    /** Tells the trainees that the survey can be filled (everyone, or only those who have not answered). */
    public function notify(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['pending_only' => ['sometimes', 'boolean'], 'title_ar' => ['nullable', 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'], 'body_ar' => ['nullable', 'string', 'max:1000'], 'body_en' => ['nullable', 'string', 'max:1000']]);
        $sent = $this->survey->notify($program, $request->user(), $data['pending_only'] ?? true, $data);

        return response()->json(['data' => ['notified' => $sent] + $this->survey->state($program->refresh())]);
    }

    private function state(Program $program): JsonResponse
    {
        return response()->json(['data' => $this->survey->state($program->refresh())]);
    }
}
