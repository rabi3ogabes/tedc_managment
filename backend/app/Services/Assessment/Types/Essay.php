<?php

namespace App\Services\Assessment\Types;

/** A written answer graded by a person, optionally against a rubric. */
class Essay extends BaseType
{
    public function key(): string
    {
        return 'essay';
    }

    public function validate(array $payload): array
    {
        $rubric = array_values(array_map(fn ($r, $i) => ['id' => (string) ($r['id'] ?? 'r'.($i + 1)), 'text' => (string) ($r['text'] ?? ''), 'points' => (float) ($r['points'] ?? 0)], $payload['rubric'] ?? [], array_keys($payload['rubric'] ?? [])));

        return ['min_words' => isset($payload['min_words']) ? (int) $payload['min_words'] : null, 'max_words' => isset($payload['max_words']) ? (int) $payload['max_words'] : null, 'allow_file' => (bool) ($payload['allow_file'] ?? false), 'rubric' => $rubric];
    }

    public function grade(array $payload, mixed $answer): array
    {
        return $this->result(0, true);
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return ['min_words' => $payload['min_words'], 'max_words' => $payload['max_words'], 'allow_file' => $payload['allow_file']];
    }

    public function correctAnswer(array $payload): mixed
    {
        return null;
    }
}
