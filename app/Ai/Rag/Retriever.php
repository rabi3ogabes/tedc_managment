<?php

namespace App\Ai\Rag;

use App\Ai\AiPolicy;
use App\Models\Embedding;
use App\Models\Employee;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the pieces of platform content that answer a question — only those the person is allowed to see. The question is
 * embedded by the active embedder and compared only with vectors of that embedder; when an embeddings model cannot be
 * used for it (switched off, not allowed for personal data, unreachable) the local embedder answers instead.
 */
class Retriever
{
    public function __construct(private readonly Embedders $embedders, private readonly Stores $stores, private readonly AiPolicy $policy) {}

    /** Programmes the person is registered in (live registrations). @return list<string> */
    public function programsOf(User $user): array
    {
        $emp = $user->id ? Employee::where('user_id', $user->id)->value('id') : null;

        return $emp ? Registration::where('employee_id', $emp)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED, Registration::STATUS_PENDING])->pluck('program_id')->all() : [];
    }

    /**
     * @return list<array{id: string, source_type: string, source_id: string, title: string, route: string, content: string, score: float, strong: bool, embedder: string, registration_id: ?string}>
     */
    public function search(User $user, string $query, int $limit = 5, ?float $min = null): array
    {
        $programs = $this->programsOf($user);
        $regs = $this->registrationIds($user);
        $scope = fn (Builder $w) => $w->where(fn ($x) => $x->where('visibility', 'public')->orWhereIn('program_id', $programs ?: ['00000000-0000-0000-0000-000000000000']));
        $out = [];
        foreach ($this->hits($query, $scope, $limit, $min, true) as $h) {
            $e = $h['row'];
            $out[] = ['id' => $e->id, 'source_type' => $e->source_type, 'source_id' => $e->source_id, 'title' => (string) $e->title,
                'route' => $e->program_id && isset($regs[$e->program_id]) ? str_replace('{registration}', $regs[$e->program_id], (string) $e->route) : (string) $e->route,
                'content' => $e->content, 'score' => $h['score'], 'strong' => $h['strong'], 'embedder' => $h['embedder'], 'registration_id' => $e->program_id ? ($regs[$e->program_id] ?? null) : null];
        }

        return $out;
    }

    /** Content of one programme for staff building material (no per-person filter). @return list<array{title: string, content: string, score: float, source_type: string, source_id: string}> */
    public function searchProgram(string $programId, string $query, int $limit = 3, ?float $min = null): array
    {
        return array_map(fn ($h) => ['title' => (string) $h['row']->title, 'content' => $h['row']->content, 'score' => $h['score'], 'source_type' => $h['row']->source_type, 'source_id' => $h['row']->source_id],
            $this->hits($query, fn (Builder $w) => $w->where('program_id', $programId), $limit, $min, false, false));
    }

    /**
     * The best piece of each source, best first.
     *
     * @param  \Closure(Builder<Embedding>): mixed  $scope
     * @return list<array{row: Embedding, score: float, strong: bool, embedder: string}>
     */
    private function hits(string $query, \Closure $scope, int $limit, ?float $min, bool $personal, bool $onePerSource = true): array
    {
        [$embedder, $vector] = $this->embed($query, $personal);
        if (! $vector || ! array_filter($vector)) {
            return [];
        }
        $kind = $embedder instanceof LocalEmbedder ? 'local' : 'model';
        $th = $this->policy->all()['retrieval'][$kind];
        $rows = $this->stores->active()->search($vector, $embedder->id(), $scope, $limit * 6, $min ?? (float) $th['min']);
        $seen = [];
        $out = [];
        foreach ($rows as [$row, $score]) {
            $k = $row->source_type.$row->source_id;
            if ($onePerSource && isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = ['row' => $row, 'score' => $score, 'strong' => $score >= (float) $th['strong'], 'embedder' => $embedder->id()];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return array{0: Embedder, 1: list<float>} the embedder used and the question's vector */
    private function embed(string $query, bool $personal): array
    {
        $active = $this->embedders->active();
        if (! $active instanceof LocalEmbedder) {
            try {
                return [$active, $active->embedMany([$query], $personal)[0]];
            } catch (EmbedderUnavailable) {
                // the local vectors answer instead
            }
        }
        $local = $this->embedders->local();

        return [$local, $local->embed($query)];
    }

    /** @return array<string, string> program id => registration id */
    private function registrationIds(User $user): array
    {
        $emp = $user->id ? Employee::where('user_id', $user->id)->value('id') : null;

        return $emp ? Registration::where('employee_id', $emp)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED, Registration::STATUS_PENDING])->pluck('id', 'program_id')->all() : [];
    }
}
