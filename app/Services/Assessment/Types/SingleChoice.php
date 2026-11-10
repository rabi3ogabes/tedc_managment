<?php

namespace App\Services\Assessment\Types;

class SingleChoice extends BaseType
{
    public function key(): string
    {
        return 'single_choice';
    }

    public function validate(array $payload): array
    {
        $options = $this->options($payload);
        if (count(array_filter($options, fn ($o) => $o['correct'])) !== 1) {
            $this->fail(__('messages.assessment.one_correct'));
        }

        return ['options' => $options];
    }

    /** @return list<array{id: string, text_ar: string, text_en: ?string, correct: bool}> */
    protected function options(array $payload): array
    {
        $options = array_values(array_map(fn ($o, $i) => ['id' => (string) ($o['id'] ?? 'o'.($i + 1)), 'text_ar' => (string) ($o['text_ar'] ?? ''), 'text_en' => $o['text_en'] ?? null, 'correct' => (bool) ($o['correct'] ?? false)], $payload['options'] ?? [], array_keys($payload['options'] ?? [])));
        if (count($options) < 2 || count($options) > 12 || count(array_unique(array_column($options, 'id'))) !== count($options) || in_array('', array_column($options, 'text_ar'), true)) {
            $this->fail(__('messages.assessment.options_invalid'));
        }

        return $options;
    }

    public function grade(array $payload, mixed $answer): array
    {
        $correct = collect($payload['options'])->firstWhere('correct', true)['id'] ?? null;

        return $this->result(is_string($answer) && $answer === $correct ? 1 : 0);
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return ['options' => $this->shuffled(array_map(fn ($o) => ['id' => $o['id'], 'text_ar' => $o['text_ar'], 'text_en' => $o['text_en']], $payload['options']), $shuffle)];
    }

    public function correctAnswer(array $payload): mixed
    {
        return collect($payload['options'])->firstWhere('correct', true)['id'] ?? null;
    }
}
