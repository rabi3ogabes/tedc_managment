<?php

namespace App\Ai\Rag;

use App\Models\Embedding;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/** Where the vectors are searched: JSON with cosine similarity in PHP, or PostgreSQL's pgvector. */
interface EmbeddingStore
{
    /** json | pgvector */
    public function name(): string;

    public function supported(): bool;

    /** Makes the store ready for vectors of one embedder (an index of the right size). */
    public function prepare(string $model, int $dim): void;

    /** Keeps the store's own copy of a row's vector, when it has one. @param  list<float>  $vector */
    public function write(Embedding $row, array $vector): void;

    /**
     * The rows of one embedder closest to a vector, best first. $scope limits the rows to what the person may see.
     *
     * @param  list<float>  $vector
     * @param  Closure(Builder<Embedding>): mixed  $scope
     * @return list<array{0: Embedding, 1: float}>
     */
    public function search(array $vector, string $model, Closure $scope, int $limit, float $min): array;
}
