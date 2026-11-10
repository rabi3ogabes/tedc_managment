<?php

namespace App\Ai\Rag;

use App\Models\Embedding;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * PostgreSQL with the pgvector extension: the vectors also live in a native column, searched by cosine distance with an
 * HNSW index per embedder (a partial index on the vectors cast to that embedder's size). Supported only where the
 * migration could add the column; everywhere else the JSON store is used.
 */
class PgVectorStore implements EmbeddingStore
{
    /** pgvector's HNSW index takes vectors up to this size; larger ones are searched without an index. */
    private const MAX_INDEXED_DIM = 2000;

    private ?bool $supported = null;

    public function name(): string
    {
        return 'pgvector';
    }

    public function supported(): bool
    {
        if ($this->supported === null) {
            try {
                $this->supported = DB::getDriverName() === 'pgsql' && Schema::hasColumn('embeddings', 'vec');
            } catch (Throwable) {
                $this->supported = false;
            }
        }

        return $this->supported;
    }

    public function prepare(string $model, int $dim): void
    {
        if (! $this->supported() || $dim < 1 || $dim > self::MAX_INDEXED_DIM) {
            return;
        }
        $name = 'embeddings_vec_'.substr(sha1($model), 0, 16);
        DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON embeddings USING hnsw ((vec::vector({$dim})) vector_cosine_ops) WHERE model = ".DB::getPdo()->quote($model));
    }

    public function write(Embedding $row, array $vector): void
    {
        if ($this->supported() && $vector) {
            DB::update('UPDATE embeddings SET vec = CAST(? AS vector) WHERE id = ?', [self::literal($vector), $row->id]);
        }
    }

    public function search(array $vector, string $model, Closure $scope, int $limit, float $min): array
    {
        $dim = count($vector);
        if ($dim === 0) {
            return [];
        }
        $q = self::literal($vector);
        $query = fn () => Embedding::query()->where('model', $model)->where('dim', $dim)->whereNotNull('vec')->where($scope)
            ->select('embeddings.*')->selectRaw("1 - ((vec::vector({$dim})) <=> CAST(? AS vector({$dim}))) AS score", [$q])
            ->orderByRaw("(vec::vector({$dim})) <=> CAST(? AS vector({$dim}))", [$q])->limit($limit)->get();
        try {
            // The index hands over more candidates than the default 40, so filtering by who may see a row still leaves enough.
            $rows = DB::transaction(function () use ($query) {
                DB::statement('SET LOCAL hnsw.ef_search = 200');

                return $query();
            });
        } catch (Throwable) {
            $rows = $query();
        }

        return $rows->map(fn (Embedding $r) => [$r, round((float) $r->getAttribute('score'), 4)])->filter(fn ($h) => $h[1] >= $min)->values()->all();
    }

    /** @param  list<float>  $vector */
    private static function literal(array $vector): string
    {
        return '['.implode(',', array_map(fn ($x) => (string) (float) $x, $vector)).']';
    }
}
