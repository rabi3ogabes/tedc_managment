<?php

namespace App\Services\Surveys;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates submitted answers against a published version's questions and keeps only the answers to the
 * questions the respondent actually saw (conditional logic and jumps are evaluated on the server).
 */
class AnswerValidator
{
    /**
     * @return array<string, mixed> the clean answers keyed by question id
     *
     * @throws ValidationException
     */
    public static function validate(array $questions, array $answers): array
    {
        $clean = [];
        $errors = [];

        LogicEngine::walk($questions, function (array $q) use (&$clean, &$errors, $answers) {
            $id = $q['id'];
            $value = $answers[$id] ?? null;
            if (! LogicEngine::filled($value)) {
                if (! empty($q['required'])) {
                    $errors["answers.$id"] = __('messages.surveys.answer_required');
                }

                return null;
            }
            $result = self::clean($q, $value);
            if ($result === null) {
                $errors["answers.$id"] = __('messages.surveys.answer_invalid');

                return null;
            }
            if ($q['type'] === 'matrix' && ! empty($q['required']) && count($result) < count($q['rows'])) {
                $errors["answers.$id"] = __('messages.surveys.matrix_incomplete');
            }
            if ($q['type'] === 'multiple' && ! empty($q['min_select']) && count($result) < $q['min_select']) {
                $errors["answers.$id"] = __('messages.surveys.select_min', ['min' => $q['min_select']]);
            }
            $clean[$id] = $result;

            return $result;
        });

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** The cleaned value of one answer, or null when it is not valid for the question. */
    public static function clean(array $q, mixed $value): mixed
    {
        $optionIds = array_column($q['options'] ?? [], 'id');
        $scale = $q['scale'] ?? ['min' => 1, 'max' => 5];

        return match ($q['type']) {
            'short_text', 'long_text' => is_string($value) && mb_strlen($value) <= ($q['max_length'] ?? 5000) ? trim($value) : null,
            'email' => is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) && mb_strlen($value) <= 200 ? Str::lower(trim($value)) : null,
            'phone' => self::phone($value),
            'number' => is_numeric($value) && abs((float) $value) < 1e12
                && (! isset($q['min']) || (float) $value >= $q['min']) && (! isset($q['max']) || (float) $value <= $q['max']) ? (float) $value : null,
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null,
            'time' => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null,
            'single', 'dropdown', 'likert' => self::choice($q, $value, $optionIds),
            'multiple' => is_array($value) && array_is_list($value) && ! array_diff($value, $optionIds) && count(array_unique($value)) === count($value)
                && count($value) <= ($q['max_select'] ?? count($optionIds)) ? array_values(array_map('strval', $value)) : null,
            'ranking' => is_array($value) && array_is_list($value) && ! array_diff($value, $optionIds) && count(array_unique($value)) === count($value) && count($value) === count($optionIds)
                ? array_values(array_map('strval', $value)) : null,
            'yes_no' => in_array($value, ['yes', 'no'], true) ? $value : null,
            'rating', 'scale' => is_numeric($value) && (float) $value == (int) $value && $value >= $scale['min'] && $value <= $scale['max'] ? (int) $value : null,
            'nps' => is_numeric($value) && (float) $value == (int) $value && $value >= 0 && $value <= 10 ? (int) $value : null,
            'matrix' => is_array($value) ? self::matrix($q, $value) : null,
            'file_upload' => is_array($value) && array_is_list($value) && count($value) <= ($q['max_files'] ?? 1)
                && ! array_filter($value, fn ($f) => ! is_string($f) || ! Str::isUuid($f)) ? array_values($value) : null,
            default => null,
        };
    }

    private static function choice(array $q, mixed $value, array $optionIds): mixed
    {
        if (in_array($value, $optionIds, true)) {
            return $value;
        }
        // "Other" with free text: {other: "..."}.
        if (! empty($q['other']) && is_array($value) && isset($value['other']) && is_string($value['other']) && trim($value['other']) !== '' && mb_strlen($value['other']) <= 300) {
            return ['other' => trim($value['other'])];
        }

        return null;
    }

    private static function phone(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $digits = preg_replace('/[\s().-]/', '', trim($value));

        return preg_match('/^\+?[0-9]{7,15}$/', $digits) ? $digits : null;
    }

    private static function matrix(array $q, array $value): ?array
    {
        $rows = array_column($q['rows'], 'id');
        $scale = $q['scale'] ?? ['min' => 1, 'max' => 5];
        $out = [];
        foreach ($value as $row => $v) {
            if (! in_array((string) $row, $rows, true) || ! is_numeric($v) || $v < $scale['min'] || $v > $scale['max']) {
                return null;
            }
            $out[(string) $row] = (int) $v;
        }

        return $out;
    }
}
