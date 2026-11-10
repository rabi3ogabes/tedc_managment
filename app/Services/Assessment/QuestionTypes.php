<?php

namespace App\Services\Assessment;

use App\Services\Assessment\Types\Categorization;
use App\Services\Assessment\Types\Dropdown;
use App\Services\Assessment\Types\Essay;
use App\Services\Assessment\Types\FillBlanks;
use App\Services\Assessment\Types\H5p;
use App\Services\Assessment\Types\Hotspot;
use App\Services\Assessment\Types\Matching;
use App\Services\Assessment\Types\Matrix;
use App\Services\Assessment\Types\MultipleSelect;
use App\Services\Assessment\Types\Numeric;
use App\Services\Assessment\Types\Ordering;
use App\Services\Assessment\Types\ShortAnswer;
use App\Services\Assessment\Types\SingleChoice;
use App\Services\Assessment\Types\TrueFalse;
use InvalidArgumentException;

/** Registry of question types. */
class QuestionTypes
{
    /** @var array<string, QuestionType>|null */
    private static ?array $types = null;

    /** @return array<string, QuestionType> */
    private static function all(): array
    {
        return self::$types ??= collect([new SingleChoice, new MultipleSelect, new TrueFalse, new Dropdown, new Matrix, new Essay, new ShortAnswer, new FillBlanks, new Matching, new Ordering, new Categorization, new Hotspot, new Numeric, new H5p])
            ->keyBy(fn (QuestionType $t) => $t->key())->all();
    }

    public static function register(QuestionType $type): void
    {
        self::all();
        self::$types[$type->key()] = $type;
    }

    public static function get(string $key): QuestionType
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown question type {$key}");
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }
}
