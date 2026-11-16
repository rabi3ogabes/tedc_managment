<?php

namespace App\Services\Assessment\Types;

use App\Services\Assessment\QuestionType;
use Illuminate\Validation\ValidationException;

abstract class BaseType implements QuestionType
{
    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['payload' => $message]);
    }

    /** @param  list<array<string, mixed>>  $items */
    protected function shuffled(array $items, bool $shuffle): array
    {
        if ($shuffle) {
            shuffle($items);
        }

        return $items;
    }

    protected function share(int $right, int $total): float
    {
        return $total > 0 ? $right / $total : 0.0;
    }

    /** @return array{ratio: float, manual: bool} */
    protected function result(float $ratio, bool $manual = false): array
    {
        return ['ratio' => max(0.0, min(1.0, $ratio)), 'manual' => $manual];
    }
}
