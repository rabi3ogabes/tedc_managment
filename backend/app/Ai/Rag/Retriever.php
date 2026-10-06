<?php

namespace App\Ai\Rag;

use App\Models\Embedding;
use App\Models\Employee;
use App\Models\Registration;
use App\Models\User;

/** Finds the pieces of platform content that answer a question — only those the person is allowed to see. */
class Retriever
{
    public function __construct(private readonly LocalEmbedder $embedder) {}

    /** Programmes the person is registered in (live registrations). @return list<string> */
    public function programsOf(User $user): array
    {
        $emp = Employee::where('user_id', $user->id)->value('id');

        return $emp ? Registration::where('employee_id', $emp)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED, Registration::STATUS_PENDING])->pluck('program_id')->all() : [];
    }

    /**
     * @return list<array{id: string, source_type: string, source_id: string, title: string, route: string, content: string, score: float, registration_id: ?string}>
     */
    public function search(User $user, string $query, int $limit = 5, float $min = 0.12): array
    {
        $programs = $this->programsOf($user);
        $q = $this->embedder->embed($query);
        if (! array_filter($q)) {
            return [];
        }
        $regs = $this->registrationIds($user);
        $rows = Embedding::query()->where(fn ($w) => $w->where('visibility', 'public')->orWhereIn('program_id', $programs ?: ['00000000-0000-0000-0000-000000000000']))->get();
        $hits = [];
        foreach ($rows as $e) {
            $score = LocalEmbedder::cosine($q, $e->vector);
            if ($score >= $min) {
                $hits[] = ['id' => $e->id, 'source_type' => $e->source_type, 'source_id' => $e->source_id, 'title' => (string) $e->title, 'route' => $e->program_id && isset($regs[$e->program_id]) ? str_replace('{registration}', $regs[$e->program_id], (string) $e->route) : (string) $e->route,
                    'content' => $e->content, 'score' => round($score, 4), 'registration_id' => $e->program_id ? ($regs[$e->program_id] ?? null) : null];
            }
        }
        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);
        // One citation per source: the best chunk of each.
        $seen = [];
        $out = [];
        foreach ($hits as $h) {
            $k = $h['source_type'].$h['source_id'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $h;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Content of one programme for staff building material (no per-person filter). @return list<array{title: string, content: string, score: float, source_type: string, source_id: string}> */
    public function searchProgram(string $programId, string $query, int $limit = 3, float $min = 0.1): array
    {
        $q = $this->embedder->embed($query);
        if (! array_filter($q)) {
            return [];
        }
        $hits = [];
        foreach (Embedding::where('program_id', $programId)->get() as $e) {
            $score = LocalEmbedder::cosine($q, $e->vector);
            if ($score >= $min) {
                $hits[] = ['title' => (string) $e->title, 'content' => $e->content, 'score' => round($score, 4), 'source_type' => $e->source_type, 'source_id' => $e->source_id];
            }
        }
        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, $limit);
    }

    /** @return array<string, string> program id => registration id */
    private function registrationIds(User $user): array
    {
        $emp = Employee::where('user_id', $user->id)->value('id');

        return $emp ? Registration::where('employee_id', $emp)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED, Registration::STATUS_PENDING])->pluck('id', 'program_id')->all() : [];
    }
}
