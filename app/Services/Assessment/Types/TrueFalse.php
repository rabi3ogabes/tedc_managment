<?php

namespace App\Services\Assessment\Types;

class TrueFalse extends BaseType
{
    public function key(): string
    {
        return 'true_false';
    }

    public function validate(array $payload): array
    {
        if (! array_key_exists('correct', $payload)) {
            $this->fail(__('messages.assessment.one_correct'));
        }

        return ['correct' => filter_var($payload['correct'], FILTER_VALIDATE_BOOLEAN)];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $given = is_bool($answer) ? $answer : (in_array(strtolower((string) $answer), ['true', '1', 'yes'], true) ? true : (in_array(strtolower((string) $answer), ['false', '0', 'no'], true) ? false : null));

        return $this->result($given !== null && $given === $payload['correct'] ? 1 : 0);
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return [];
    }

    public function correctAnswer(array $payload): mixed
    {
        return $payload['correct'];
    }
}
