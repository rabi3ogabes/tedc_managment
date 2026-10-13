<?php

namespace App\Ai\Rag;

use App\Ai\AiPolicy;
use App\Models\AiIndexRun;
use App\Models\Embedding;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fills the retrieval index for one embedder. The local vectors are the list of pieces: they are brought up to date
 * first, then every piece the embedder lacks (or holds for an older text) is embedded in batches. What is already there
 * is skipped, so a run that failed, or stopped at its time budget, continues where it was; the embedder becomes the
 * active one only when nothing is missing.
 */
class Reindexer
{
    public function __construct(private readonly Indexer $indexer, private readonly Embedders $embedders, private readonly Stores $stores, private readonly AiPolicy $policy) {}

    /** @throws EmbedderUnavailable when the model does not exist */
    public function start(string $target, ?User $by = null): AiIndexRun
    {
        return AiIndexRun::create(['model' => $this->embedders->for($target)->id(), 'status' => 'queued', 'started_by' => $by?->id]);
    }

    /** Works on a run until it is done, fails, or $budgetSeconds runs out (it then stays 'running'). */
    public function run(AiIndexRun $run, ?float $budgetSeconds = null, int $batch = 50, ?callable $progress = null): AiIndexRun
    {
        if ($run->status === 'done') {
            return $run;
        }
        $lock = Cache::lock('ai-reindex', 3600);
        if (! $lock->get()) {
            return $run;   // another run is working; this one waits its turn
        }
        try {
            return $this->work($run->fresh(), $budgetSeconds, max(1, $batch), $progress);
        } finally {
            $lock->release();
        }
    }

    private function work(AiIndexRun $run, ?float $budgetSeconds, int $batch, ?callable $progress): AiIndexRun
    {
        $deadline = $budgetSeconds !== null ? microtime(true) + $budgetSeconds : null;
        try {
            $embedder = $this->embedders->for($run->model);
            if (in_array($run->status, ['queued', 'failed'], true)) {
                if ($run->status === 'queued') {
                    $this->indexer->all([$this->embedders->local()]);
                }
                $total = Embedding::where('model', LocalEmbedder::ID)->count();
                $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'total' => $total, 'done' => $total - $this->missing($run->model)->count(), 'error' => null]);
            }
            if (! $embedder instanceof LocalEmbedder) {
                while (($rows = $this->missing($run->model)->limit($batch)->get())->isNotEmpty()) {
                    $vectors = $embedder->embedMany($rows->map(fn (Embedding $r) => $r->title.' '.$r->content)->all());
                    foreach ($rows->values() as $k => $local) {
                        $row = Embedding::updateOrCreate(['source_type' => $local->source_type, 'source_id' => $local->source_id, 'chunk_no' => $local->chunk_no, 'model' => $run->model], [
                            'program_id' => $local->program_id, 'visibility' => $local->visibility, 'lang' => $local->lang, 'title' => $local->title, 'route' => $local->route, 'content' => $local->content,
                            'vector' => $vectors[$k], 'dim' => count($vectors[$k]), 'content_hash' => $local->content_hash, 'updated_at' => now(),
                        ]);
                        foreach ($this->stores->writers() as $store) {
                            $store->write($row, $vectors[$k]);
                        }
                    }
                    $run->update(['done' => min($run->total, $run->done + $rows->count()), 'dim' => count($vectors[0])]);
                    $progress && $progress($run);
                    if ($deadline !== null && microtime(true) >= $deadline) {
                        return $run;   // continues on the next call
                    }
                }
            }

            return $this->finish($run, $embedder);
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'error' => mb_substr($e instanceof EmbedderUnavailable ? 'The embeddings model could not be reached.' : $e->getMessage(), 0, 500)]);

            return $run;
        }
    }

    private function finish(AiIndexRun $run, Embedder $embedder): AiIndexRun
    {
        // Pieces the embedder still holds for content that changed or went away.
        Embedding::where('model', $run->model)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('embeddings as l')->where('l.model', LocalEmbedder::ID)
            ->whereColumn('l.source_type', 'embeddings.source_type')->whereColumn('l.source_id', 'embeddings.source_id')->whereColumn('l.chunk_no', 'embeddings.chunk_no')->whereColumn('l.content_hash', 'embeddings.content_hash'))
            ->when($run->model === LocalEmbedder::ID, fn ($q) => $q->whereRaw('1 = 0'))->delete();
        $dim = (int) (Embedding::where('model', $run->model)->value('dim') ?? $embedder->dim() ?? 0);
        $this->stores->pgvector()->prepare($run->model, $dim);
        $this->policy->activate($embedder instanceof ModelEmbedder ? $embedder->modelId() : 'local');
        // Vectors of an embedder that is neither the local one nor the active one are no longer used.
        Embedding::whereNotIn('model', [LocalEmbedder::ID, $run->model])->delete();
        $run->update(['status' => 'done', 'done' => $run->total, 'dim' => $dim ?: null, 'finished_at' => now(), 'error' => null]);

        return $run;
    }

    /** Local pieces the embedder does not hold for their current text. @return \Illuminate\Database\Eloquent\Builder<Embedding> */
    private function missing(string $model)
    {
        return Embedding::query()->from('embeddings as l')->select('l.*')->where('l.model', LocalEmbedder::ID)
            ->when($model !== LocalEmbedder::ID, fn ($q) => $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('embeddings as t')->where('t.model', $model)
                ->whereColumn('t.source_type', 'l.source_type')->whereColumn('t.source_id', 'l.source_id')->whereColumn('t.chunk_no', 'l.chunk_no')->whereColumn('t.content_hash', 'l.content_hash')))
            ->when($model === LocalEmbedder::ID, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('l.id');
    }
}
