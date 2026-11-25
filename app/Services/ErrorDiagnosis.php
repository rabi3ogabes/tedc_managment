<?php

namespace App\Services;

use App\Models\ErrorLog;
use App\Services\Ai\AiGateway;

/**
 * Asks the model assigned to "content" (Settings → AI models, for example a Claude key) to read one error and answer
 * with a diagnosis: the likely cause, the steps that fix it, the files involved and how to verify. The answer is kept on
 * the entry. Nothing personal leaves the platform: the prompt is the same scrubbed one as "Copy for Claude".
 */
class ErrorDiagnosis
{
    public const SCHEMA = [
        'type' => 'object',
        'required' => ['cause', 'steps', 'verification', 'confidence'],
        'properties' => [
            'cause' => ['type' => 'string', 'description' => 'The root cause in two or three plain sentences.'],
            'steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ordered, concrete changes that fix it.'],
            'files' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Repository paths most likely involved.'],
            'verification' => ['type' => 'string', 'description' => 'How to prove the fix (a test to add or run, what to check).'],
            'outside_code' => ['type' => 'string', 'description' => 'What to change instead, when the cause is data, a setting or something outside the code; empty otherwise.'],
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
        ],
    ];

    public function __construct(private readonly AiGateway $gateway, private readonly ErrorPromptBuilder $prompts) {}

    /** Whether a model is assigned for this work. */
    public function available(): bool
    {
        return $this->gateway->has('text');
    }

    /** @return array<string, mixed>|null the diagnosis, or null when the model did not answer */
    public function diagnose(ErrorLog $log): ?array
    {
        $system = 'You are a senior engineer on the TEDC training platform (Laravel 13 API in backend/, React + TypeScript in web/, Flutter in mobile/). '
            .'Diagnose the error below for the team that maintains the code. Be specific and brief; name files by repository path when you can; never invent library names. '
            .'Answer in the language of the error message when it is Arabic, otherwise in English.';
        $answer = $this->gateway->json($system, $this->prompts->build(collect([$log]), 1), self::SCHEMA, 1500);
        if (! is_array($answer) || ! is_string($answer['cause'] ?? null)) {
            return null;
        }
        $clean = [
            'cause' => mb_substr(trim($answer['cause']), 0, 1500),
            'steps' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? mb_substr(trim($s), 0, 600) : '', (array) ($answer['steps'] ?? [])))),
            'files' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? mb_substr(trim($s), 0, 200) : '', (array) ($answer['files'] ?? [])))),
            'verification' => mb_substr(trim((string) ($answer['verification'] ?? '')), 0, 800),
            'outside_code' => mb_substr(trim((string) ($answer['outside_code'] ?? '')), 0, 800),
            'confidence' => in_array($answer['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $answer['confidence'] : 'medium',
        ];
        $log->forceFill(['ai_diagnosis' => $clean, 'ai_diagnosed_at' => now()])->save();

        return $clean;
    }
}
