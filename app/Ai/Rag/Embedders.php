<?php

namespace App\Ai\Rag;

use App\Ai\AiGuard;
use App\Ai\AiPolicy;
use App\Models\AiIndexRun;
use App\Services\Ai\AiModelSettings;

/**
 * Which embedder retrieval uses. The local embedder always keeps its vectors (the fallback when a model cannot be
 * reached); an embeddings model becomes the active one only after a re-embedding run with it has finished, so a search
 * never meets a half-filled index.
 */
class Embedders
{
    public function __construct(private readonly LocalEmbedder $local, private readonly AiPolicy $policy, private readonly AiModelSettings $models, private readonly AiGuard $guard) {}

    public function local(): LocalEmbedder
    {
        return $this->local;
    }

    /** What retrieval uses now. */
    public function active(): Embedder
    {
        $active = (string) ($this->policy->all()['retrieval']['active'] ?? 'local');

        return $active === 'local' || ! $this->exists($active) ? $this->local : new ModelEmbedder($active, $this->guard);
    }

    /** An embedder by the id stored with vectors, or by a model id ('local' for the local one). */
    public function for(?string $id): Embedder
    {
        if ($id === null || $id === '' || $id === 'local' || $id === LocalEmbedder::ID) {
            return $this->local;
        }
        $modelId = str_starts_with($id, 'model:') ? substr($id, 6) : $id;
        if (! $this->exists($modelId)) {
            throw new EmbedderUnavailable('No embeddings model with this id.');
        }

        return new ModelEmbedder($modelId, $this->guard);
    }

    /** The embedders whose vectors are kept up to date when content changes: the local one, the active one, and the one a run is filling. @return list<Embedder> */
    public function live(): array
    {
        $out = [LocalEmbedder::ID => $this->local];
        $active = $this->active();
        $out[$active->id()] = $active;
        $filling = AiIndexRun::whereIn('status', ['queued', 'running'])->where('model', '!=', LocalEmbedder::ID)->latest('created_at')->value('model');
        if ($filling && ! isset($out[$filling])) {
            try {
                $out[$filling] = $this->for($filling);
            } catch (EmbedderUnavailable) {
                // the model was removed while a run was open
            }
        }

        return array_values($out);
    }

    /** The embeddings models an administrator may choose. @return list<array{id: string, label: string, residency: string}> */
    public function choices(): array
    {
        $all = $this->models->all();

        return collect($all['models'])->filter(fn ($m) => in_array('embeddings', $m['tasks'] ?? [], true) && ($m['enabled'] ?? true))
            ->map(fn ($m) => ['id' => $m['id'], 'label' => ($m['label'] ?? '') ?: $m['model'], 'residency' => $this->models->connection($m['connection_id'])['data_residency'] ?? 'external'])->values()->all();
    }

    private function exists(string $modelId): bool
    {
        $m = $this->models->model($modelId);

        return $m !== null && in_array('embeddings', $m['tasks'] ?? [], true);
    }
}
