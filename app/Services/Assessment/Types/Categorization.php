<?php

namespace App\Services\Assessment\Types;

/** Items to drop into buckets: the trainee sends {itemId: bucketId}. */
class Categorization extends BaseType
{
    public function key(): string
    {
        return 'categorization';
    }

    public function validate(array $payload): array
    {
        $buckets = array_values(array_map(fn ($b, $i) => ['id' => (string) ($b['id'] ?? 'b'.($i + 1)), 'name' => (string) ($b['name'] ?? '')], $payload['buckets'] ?? [], array_keys($payload['buckets'] ?? [])));
        $items = array_values(array_map(fn ($x, $i) => ['id' => (string) ($x['id'] ?? 'i'.($i + 1)), 'text' => (string) ($x['text'] ?? ''), 'bucket' => (string) ($x['bucket'] ?? '')], $payload['items'] ?? [], array_keys($payload['items'] ?? [])));
        if (count($buckets) < 2 || count($items) < 2 || array_diff(array_column($items, 'bucket'), array_column($buckets, 'id'))) {
            $this->fail(__('messages.assessment.pairs_invalid'));
        }

        return ['buckets' => $buckets, 'items' => $items];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $answer = (array) $answer;

        return $this->result($this->share(count(array_filter($payload['items'], fn ($i) => ($answer[$i['id']] ?? null) === $i['bucket'])), count($payload['items'])));
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return ['buckets' => $payload['buckets'], 'items' => $this->shuffled(array_map(fn ($i) => ['id' => $i['id'], 'text' => $i['text']], $payload['items']), true)];
    }

    public function correctAnswer(array $payload): mixed
    {
        return collect($payload['items'])->mapWithKeys(fn ($i) => [$i['id'] => $i['bucket']])->all();
    }
}
