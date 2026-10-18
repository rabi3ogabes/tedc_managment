<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Programs\ProgramVoiceAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The spoken or typed order to create a programme: what is available, and the reading of one request into a draft the person verifies. */
class ProgramAssistantController extends Controller
{
    public function __construct(private readonly ProgramVoiceAssistant $assistant) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->assistant->status()]);
    }

    public function parse(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'min:3', 'max:1000'], 'lang' => ['nullable', 'in:ar,en'], 'source' => ['nullable', 'in:voice,text']]);
        $result = $this->assistant->parse($data['text'], $data['lang'] ?? null, $this->user());
        AuditLog::create(['user_id' => $this->user()->id, 'action' => 'program_request_parsed', 'auditable_type' => 'program_request', 'auditable_id' => null,
            'new_values' => ['source' => $data['source'] ?? 'text', 'language' => $result['language'], 'engine' => $result['engine'], 'chars' => mb_strlen($data['text']), 'confidence' => $result['confidence'], 'missing' => $result['missing']]]);

        return response()->json(['data' => $result]);
    }
}
