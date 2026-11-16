<?php

namespace App\Services\Kits;

use Anthropic\Client;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Thin structured-output wrapper around Claude for the Training Kit Studio. */
class KitAi
{
    public function __construct(private readonly AiGateway $gateway) {}

    /** A content model chosen in Settings → AI models, or the Anthropic key from the environment. */
    public function enabled(): bool
    {
        return $this->gateway->has('text') || filled(config('tedc.ai.api_key'));
    }

    /**
     * Asks Claude for JSON matching the schema. Returns null when AI is off, refused or failed,
     * so callers always have a deterministic fallback.
     */
    public function json(string $system, string $prompt, array $schema, ?int $maxTokens = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        if ($this->gateway->has('text')) {
            $data = $this->gateway->json($system, $prompt, $schema, $maxTokens);
            if ($data !== null || blank(config('tedc.ai.api_key'))) {
                return $data;
            }
        }

        try {
            $client = new Client(apiKey: config('tedc.ai.api_key'), requestOptions: ['timeout' => (float) config('tedc.ai.timeout')]);
            $message = $client->messages->create(
                model: config('tedc.ai.model'),
                maxTokens: $maxTokens ?? (int) config('tedc.ai.max_tokens'),
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            );

            if ($message->stopReason === 'refusal') {
                return null;
            }
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $data = json_decode($block->text, true);
                    if (is_array($data)) {
                        return $data;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Kit AI call failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
