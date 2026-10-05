<?php

namespace App\Services\Assessment\Types;

class Numeric extends BaseType
{
    public function key(): string
    {
        return 'numeric';
    }

    public function validate(array $payload): array
    {
        if (! isset($payload['value']) || ! is_numeric($payload['value'])) {
            $this->fail(__('messages.assessment.number_required'));
        }

        return ['value' => (float) $payload['value'], 'tolerance' => max(0.0, (float) ($payload['tolerance'] ?? 0)), 'tolerance_percent' => isset($payload['tolerance_percent']) ? max(0.0, (float) $payload['tolerance_percent']) : null, 'unit' => $payload['unit'] ?? null];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $raw = is_scalar($answer) ? strtr((string) $answer, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', ',' => '.']) : '';
        if (! is_numeric($raw)) {
            return $this->result(0);
        }
        $tol = $payload['tolerance_percent'] !== null ? abs($payload['value']) * $payload['tolerance_percent'] / 100 : $payload['tolerance'];

        return $this->result(abs((float) $raw - $payload['value']) <= $tol + 1e-9 ? 1 : 0);
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return ['unit' => $payload['unit']];
    }

    public function correctAnswer(array $payload): mixed
    {
        return $payload['value'];
    }
}
