<?php

namespace App\Ai\Rag;

/** Turns text into vectors for retrieval. Each embedder has a stable id stored with every vector it makes. */
interface Embedder
{
    /** local-hash-256, or model:<model id> for a configured embeddings model. */
    public function id(): string;

    /** The size of its vectors, when known. */
    public function dim(): ?int;

    /**
     * @param  list<string>  $texts
     * @param  bool  $personal  the texts may hold personal data (a person's question), so the residency rules apply
     * @return list<list<float>> one L2-normalised vector per text
     *
     * @throws EmbedderUnavailable
     */
    public function embedMany(array $texts, bool $personal = false): array;
}
