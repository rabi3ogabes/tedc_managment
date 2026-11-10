<?php

namespace App\Ai\Rag;

use App\Ai\AiPolicy;

/** The store retrieval searches: pgvector where the database offers it (unless Settings → AI says JSON), the JSON store otherwise. */
class Stores
{
    public function __construct(private readonly JsonCosineStore $json, private readonly PgVectorStore $pg, private readonly AiPolicy $policy) {}

    public function active(): EmbeddingStore
    {
        $choice = (string) ($this->policy->all()['retrieval']['store'] ?? 'auto');

        return $choice !== 'json' && $this->pg->supported() ? $this->pg : $this->json;
    }

    /** Stores that keep their own copy of the vectors and must be written on every change (pgvector, whenever it exists). @return list<EmbeddingStore> */
    public function writers(): array
    {
        return $this->pg->supported() ? [$this->pg] : [];
    }

    public function pgvector(): PgVectorStore
    {
        return $this->pg;
    }
}
