<?php

namespace App\Services\Assessment\Types;

use App\Services\Assessment\ArabicText;

/** Text with several blanks (`{{1}}`, `{{2}}`), each with its accepted spellings. */
class FillBlanks extends BaseType
{
    public function key(): string
    {
        return 'fill_blanks';
    }

    public function validate(array $payload): array
    {
        $blanks = [];
        foreach ($payload['blanks'] ?? [] as $i => $b) {
            $accepted = array_values(array_filter(array_map(fn ($a) => trim((string) $a), $b['accepted'] ?? []), fn ($a) => $a !== ''));
            if (! $accepted) {
                $this->fail(__('messages.assessment.accepted_required'));
            }
            $blanks[] = ['id' => (string) ($b['id'] ?? 'b'.($i + 1)), 'accepted' => $accepted];
        }
        if (! $blanks) {
            $this->fail(__('messages.assessment.blanks_required'));
        }

        return ['text_ar' => (string) ($payload['text_ar'] ?? ''), 'text_en' => $payload['text_en'] ?? null, 'blanks' => $blanks, 'case_sensitive' => (bool) ($payload['case_sensitive'] ?? false)];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $answer = (array) $answer;
        $right = count(array_filter($payload['blanks'], fn ($b) => isset($answer[$b['id']]) && is_scalar($answer[$b['id']]) && ArabicText::matches((string) $answer[$b['id']], $b['accepted'], $payload['case_sensitive'])));

        return $this->result($this->share($right, count($payload['blanks'])));
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return ['text_ar' => $payload['text_ar'], 'text_en' => $payload['text_en'], 'blanks' => array_map(fn ($b) => ['id' => $b['id']], $payload['blanks'])];
    }

    public function correctAnswer(array $payload): mixed
    {
        return collect($payload['blanks'])->mapWithKeys(fn ($b) => [$b['id'] => $b['accepted'][0]])->all();
    }
}
