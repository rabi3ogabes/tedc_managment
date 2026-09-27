<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\AiAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistantController extends Controller
{
    public function ask(Request $request, AiAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:8000'],
        ]);

        return response()->json(['data' => $assistant->ask($this->user(), $data['question'], $data['history'] ?? [])]);
    }

    public function history(): JsonResponse
    {
        return response()->json(['data' => Report::where('type', 'ai_insight')->where('generated_by', $this->user()->id)->latest()->limit(30)->get()]);
    }

    public function status(AiAssistant $assistant): JsonResponse
    {
        return response()->json(['data' => ['llm_enabled' => $assistant->enabled(), 'model' => $assistant->enabled() ? config('tedc.ai.model') : null]]);
    }
}
