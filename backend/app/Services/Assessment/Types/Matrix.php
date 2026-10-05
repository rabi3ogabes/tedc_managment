<?php

namespace App\Services\Assessment\Types;

/** A grid of rows and columns: a quiz grid when a key is given, otherwise a Likert-style grid that scores completeness. */
class Matrix extends BaseType
{
    public function key(): string
    {
        return 'matrix';
    }

    public function validate(array $payload): array
    {
        $rows = $this->items($payload['rows'] ?? [], 'r');
        $cols = $this->items($payload['cols'] ?? [], 'c');
        if (count($rows) < 1 || count($cols) < 2) {
            $this->fail(__('messages.assessment.options_invalid'));
        }
        $correct = null;
        if (! empty($payload['correct'])) {
            foreach ($payload['correct'] as $r => $c) {
                if (! in_array($r, array_column($rows, 'id'), true) || array_diff((array) $c, array_column($cols, 'id'))) {
                    $this->fail(__('messages.assessment.options_invalid'));
                }
            }
            $correct = $payload['correct'];
        }

        return ['rows' => $rows, 'cols' => $cols, 'multiple' => (bool) ($payload['multiple'] ?? false), 'correct' => $correct];
    }

    private function items(array $list, string $prefix): array
    {
        return array_values(array_map(fn ($x, $i) => ['id' => (string) ($x['id'] ?? $prefix.($i + 1)), 'text' => (string) ($x['text'] ?? '')], $list, array_keys($list)));
    }

    public function grade(array $payload, mixed $answer): array
    {
        $answer = (array) $answer;
        $rows = array_column($payload['rows'], 'id');
        if (! $payload['correct']) {
            return $this->result($this->share(count(array_filter($rows, fn ($r) => ! empty($answer[$r]))), count($rows)));
        }
        $right = 0;
        foreach ($rows as $r) {
            $want = (array) ($payload['correct'][$r] ?? []);
            $got = (array) ($answer[$r] ?? []);
            sort($want);
            sort($got);
            $right += $want === $got ? 1 : 0;
        }

        return $this->result($this->share($right, count($rows)));
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return ['rows' => $payload['rows'], 'cols' => $payload['cols'], 'multiple' => $payload['multiple']];
    }

    public function correctAnswer(array $payload): mixed
    {
        return $payload['correct'];
    }
}
