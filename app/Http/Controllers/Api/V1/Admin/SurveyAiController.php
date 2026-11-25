<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Services\Surveys\SurveyAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Survey Studio v2, Gate 8: AI summaries of opted-in open text and question suggestions, under the AI policy. */
class SurveyAiController extends Controller
{
    public function __construct(private readonly SurveyAi $ai) {}

    public function status(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['data' => $this->ai->status($studioSurvey)]);
    }

    public function summarise(Request $request, Survey $studioSurvey): JsonResponse
    {
        $d = $request->validate(['question_id' => ['nullable', 'string', 'max:40']]);

        return response()->json(['data' => $this->ai->present($this->ai->summarise($studioSurvey, $d['question_id'] ?? null, $this->user(), app()->getLocale()))], 201);
    }

    public function suggest(Request $request, Survey $studioSurvey): JsonResponse
    {
        $d = $request->validate(['goal' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json(['data' => $this->ai->present($this->ai->suggest($studioSurvey, $d['goal'], $this->user(), app()->getLocale()))], 201);
    }
}
