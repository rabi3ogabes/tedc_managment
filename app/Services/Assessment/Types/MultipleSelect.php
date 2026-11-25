<?php

namespace App\Services\Assessment\Types;

class MultipleSelect extends SingleChoice
{
    public function key(): string
    {
        return 'multiple_select';
    }

    public function validate(array $payload): array
    {
        $options = $this->options($payload);
        if (count(array_filter($options, fn ($o) => $o['correct'])) < 1) {
            $this->fail(__('messages.assessment.one_correct'));
        }

        return ['options' => $options, 'partial' => (bool) ($payload['partial'] ?? true)];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $correct = collect($payload['options'])->where('correct', true)->pluck('id')->all();
        $picked = array_values(array_unique(array_map('strval', (array) $answer)));
        $right = count(array_intersect($picked, $correct));
        $wrong = count(array_diff($picked, $correct));
        if (! ($payload['partial'] ?? true)) {
            return $this->result($right === count($correct) && $wrong === 0 ? 1 : 0);
        }

        return $this->result(($right - $wrong) / count($correct));
    }

    public function correctAnswer(array $payload): mixed
    {
        return collect($payload['options'])->where('correct', true)->pluck('id')->values()->all();
    }
}
