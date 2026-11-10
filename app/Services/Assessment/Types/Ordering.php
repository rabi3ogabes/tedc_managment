<?php

namespace App\Services\Assessment\Types;

/** Items to put in order; the order authors give is the correct one. */
class Ordering extends BaseType
{
    public function key(): string
    {
        return 'ordering';
    }

    public function validate(array $payload): array
    {
        $items = array_values(array_map(fn ($x, $i) => ['id' => (string) ($x['id'] ?? 'i'.($i + 1)), 'text' => (string) ($x['text'] ?? '')], $payload['items'] ?? [], array_keys($payload['items'] ?? [])));
        if (count($items) < 2 || in_array('', array_column($items, 'text'), true)) {
            $this->fail(__('messages.assessment.pairs_invalid'));
        }

        return ['items' => $items, 'strict' => (bool) ($payload['strict'] ?? false)];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $want = array_column($payload['items'], 'id');
        $got = array_values((array) $answer);
        if ($payload['strict']) {
            return $this->result($want === $got ? 1 : 0);
        }
        $right = 0;
        foreach ($want as $i => $id) {
            $right += ($got[$i] ?? null) === $id ? 1 : 0;
        }

        return $this->result($this->share($right, count($want)));
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        $items = $payload['items'];
        do {
            shuffle($items);
        } while (count($items) > 1 && array_column($items, 'id') === array_column($payload['items'], 'id'));

        return ['items' => $items];
    }

    public function correctAnswer(array $payload): mixed
    {
        return array_column($payload['items'], 'id');
    }
}
