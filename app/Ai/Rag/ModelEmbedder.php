<?php

namespace App\Ai\Rag;

use App\Ai\AiGuard;

/**
 * Vectors from an embeddings model configured in Settings → AI models (Azure OpenAI in Qatar is the intended target).
 * Every call goes through AiGuard: the AI feature must be on, a person's question is redacted and may only go to a
 * model whose data stays where the policy allows, and the call is logged.
 */
class ModelEmbedder implements Embedder
{
    private ?int $dim = null;

    public function __construct(private readonly string $modelId, private readonly AiGuard $guard) {}

    public function id(): string
    {
        return 'model:'.$this->modelId;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function dim(): ?int
    {
        return $this->dim;
    }

    public function embedMany(array $texts, bool $personal = false): array
    {
        if ($texts === []) {
            return [];
        }
        $vectors = $this->guard->embed($this->modelId, array_values($texts), $personal);
        if ($vectors === null || count($vectors) !== count($texts)) {
            throw new EmbedderUnavailable('The embeddings model is not available.');
        }
        $this->dim = count($vectors[0]);

        return $vectors;
    }
}
