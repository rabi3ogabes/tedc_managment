<?php

namespace App\Services\Assessment\Types;

/** Pairs to join: the trainee sends {leftId: rightId}. */
class Matching extends BaseType
{
    public function key(): string
    {
        return 'matching';
    }

    public function validate(array $payload): array
    {
        $pairs = array_values(array_map(fn ($p, $i) => ['id' => (string) ($p['id'] ?? 'p'.($i + 1)), 'left' => (string) ($p['left'] ?? ''), 'right' => (string) ($p['right'] ?? '')], $payload['pairs'] ?? [], array_keys($payload['pairs'] ?? [])));
        if (count($pairs) < 2 || in_array('', array_column($pairs, 'left'), true) || in_array('', array_column($pairs, 'right'), true)) {
            $this->fail(__('messages.assessment.pairs_invalid'));
        }

        return ['pairs' => $pairs];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $answer = (array) $answer;

        return $this->result($this->share(count(array_filter($payload['pairs'], fn ($p) => ($answer[$p['id']] ?? null) === $p['id'])), count($payload['pairs'])));
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        $rights = array_map(fn ($p) => ['id' => $p['id'], 'text' => $p['right']], $payload['pairs']);
        shuffle($rights);   // the right-hand column is always shuffled, or the answer would sit beside the question

        return ['lefts' => array_map(fn ($p) => ['id' => $p['id'], 'text' => $p['left']], $this->shuffled($payload['pairs'], $shuffle)), 'rights' => $rights];
    }

    public function correctAnswer(array $payload): mixed
    {
        return collect($payload['pairs'])->mapWithKeys(fn ($p) => [$p['id'] => $p['id']])->all();
    }
}
