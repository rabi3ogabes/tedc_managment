<?php

namespace App\Ai\Rag;

use App\Models\Embedding;
use Closure;

/** Vectors kept as JSON and compared in PHP (cosine of normalised vectors is their dot product). Works on every database. */
class JsonCosineStore implements EmbeddingStore
{
    public function name(): string
    {
        return 'json';
    }

    public function supported(): bool
    {
        return true;
    }

    public function prepare(string $model, int $dim): void {}

    public function write(Embedding $row, array $vector): void {}

    public function search(array $vector, string $model, Closure $scope, int $limit, float $min): array
    {
        $hits = [];
        foreach (Embedding::where('model', $model)->where($scope)->get() as $row) {
            $score = LocalEmbedder::cosine($vector, (array) $row->vector);
            if ($score >= $min) {
                $hits[] = [$row, round($score, 4)];
            }
        }
        usort($hits, fn ($a, $b) => $b[1] <=> $a[1]);

        return array_slice($hits, 0, $limit);
    }
}
