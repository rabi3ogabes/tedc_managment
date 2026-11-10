<?php

namespace App\Services\Assessment\Types;

/** A sentence with inline selects: `{{1}}`, `{{2}}` mark where each blank sits. */
class Dropdown extends BaseType
{
    public function key(): string
    {
        return 'dropdown';
    }

    public function validate(array $payload): array
    {
        $blanks = [];
        foreach ($payload['blanks'] ?? [] as $i => $b) {
            $opts = array_values(array_map(fn ($o, $k) => ['id' => (string) ($o['id'] ?? 'o'.($k + 1)), 'text' => (string) ($o['text'] ?? '')], $b['options'] ?? [], array_keys($b['options'] ?? [])));
            if (count($opts) < 2 || ! in_array($b['correct'] ?? null, array_column($opts, 'id'), true)) {
                $this->fail(__('messages.assessment.options_invalid'));
            }
            $blanks[] = ['id' => (string) ($b['id'] ?? 'b'.($i + 1)), 'options' => $opts, 'correct' => (string) $b['correct']];
        }
        if (! $blanks) {
            $this->fail(__('messages.assessment.blanks_required'));
        }

        return ['template_ar' => (string) ($payload['template_ar'] ?? ''), 'template_en' => $payload['template_en'] ?? null, 'blanks' => $blanks];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $answer = (array) $answer;
        $right = count(array_filter($payload['blanks'], fn ($b) => ($answer[$b['id']] ?? null) === $b['correct']));

        return $this->result($this->share($right, count($payload['blanks'])));
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return ['template_ar' => $payload['template_ar'], 'template_en' => $payload['template_en'], 'blanks' => array_map(fn ($b) => ['id' => $b['id'], 'options' => $this->shuffled($b['options'], $shuffle)], $payload['blanks'])];
    }

    public function correctAnswer(array $payload): mixed
    {
        return collect($payload['blanks'])->mapWithKeys(fn ($b) => [$b['id'] => $b['correct']])->all();
    }
}
