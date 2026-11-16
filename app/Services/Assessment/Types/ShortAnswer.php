<?php

namespace App\Services\Assessment\Types;

use App\Services\Assessment\ArabicText;

class ShortAnswer extends BaseType
{
    public function key(): string
    {
        return 'short_answer';
    }

    public function validate(array $payload): array
    {
        $accepted = array_values(array_filter(array_map(fn ($a) => trim((string) $a), $payload['accepted'] ?? []), fn ($a) => $a !== ''));
        if (! $accepted) {
            $this->fail(__('messages.assessment.accepted_required'));
        }

        return ['accepted' => $accepted, 'case_sensitive' => (bool) ($payload['case_sensitive'] ?? false)];
    }

    public function grade(array $payload, mixed $answer): array
    {
        return $this->result(is_scalar($answer) && ArabicText::matches((string) $answer, $payload['accepted'], $payload['case_sensitive']) ? 1 : 0);
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return [];
    }

    public function correctAnswer(array $payload): mixed
    {
        return $payload['accepted'][0] ?? null;
    }
}
