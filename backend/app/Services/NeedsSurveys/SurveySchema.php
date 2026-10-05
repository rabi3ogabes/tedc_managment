<?php

namespace App\Services\NeedsSurveys;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The question model shared by the builder, templates, the Word / Excel importer and the
 * respondent forms, plus validation of submitted answers (including conditional logic).
 *
 * Question: {id, type, title, description?, required, options?[{id,label,skill_id?,skill_name?}],
 *            rows?[{id,label,skill_id?,skill_name?}], scale?{min,max,min_label?,max_label?},
 *            mode? (competence|need), skill_id?, skill_name?, max_select?, show_if?{question,op,value}}
 */
class SurveySchema
{
    public const TYPES = ['section', 'short_text', 'long_text', 'single', 'multiple', 'dropdown', 'yes_no', 'rating', 'scale', 'nps', 'matrix', 'ranking', 'number', 'date'];

    public const CHOICE_TYPES = ['single', 'multiple', 'dropdown', 'ranking'];

    public const OPERATORS = ['equals', 'not_equals', 'includes', 'gte', 'lte', 'answered'];

    /** Cleans and validates a question list coming from an administrator. */
    public static function normalize(array $questions): array
    {
        if (! array_filter($questions, fn ($q) => ($q['type'] ?? '') !== 'section')) {
            throw ValidationException::withMessages(['questions' => __('Add at least one question.')]);
        }
        if (count($questions) > 150) {
            throw ValidationException::withMessages(['questions' => __('A survey can hold at most 150 questions.')]);
        }

        $ids = [];
        $clean = [];
        foreach (array_values($questions) as $i => $q) {
            $type = $q['type'] ?? null;
            $title = trim((string) ($q['title'] ?? ''));
            if (! in_array($type, self::TYPES, true)) {
                throw ValidationException::withMessages(["questions.$i.type" => __('Unknown question type.')]);
            }
            if ($title === '') {
                throw ValidationException::withMessages(["questions.$i.title" => __('Every question needs a title.')]);
            }

            $id = preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) ($q['id'] ?? '')) ? $q['id'] : 'q'.Str::lower(Str::random(8));
            while (isset($ids[$id])) {
                $id = 'q'.Str::lower(Str::random(8));
            }
            $ids[$id] = true;

            $item = [
                'id' => $id,
                'type' => $type,
                'title' => Str::limit($title, 500, ''),
                'description' => isset($q['description']) && trim((string) $q['description']) !== '' ? Str::limit(trim((string) $q['description']), 1000, '') : null,
                'required' => $type !== 'section' && (bool) ($q['required'] ?? false),
            ];

            // Bilingual titles and evidence uploads are used by the evaluation forms; surveys that do not set them are unchanged.
            if (self::text($q['title_en'] ?? null) !== null) {
                $item['title_en'] = Str::limit(trim((string) $q['title_en']), 500, '');
            }
            if (! empty($q['evidence'])) {
                $item['evidence'] = true;
            }

            if (in_array($type, self::CHOICE_TYPES, true)) {
                $item['options'] = self::items($q['options'] ?? [], 'o');
                if (count($item['options']) < 2) {
                    throw ValidationException::withMessages(["questions.$i.options" => __('Choice questions need at least two options.')]);
                }
                if ($type === 'multiple' && ! empty($q['max_select'])) {
                    $item['max_select'] = max(1, min(count($item['options']), (int) $q['max_select']));
                }
            }

            if ($type === 'matrix') {
                $item['rows'] = self::items($q['rows'] ?? [], 'r');
                if (! $item['rows']) {
                    throw ValidationException::withMessages(["questions.$i.rows" => __('A matrix needs at least one statement.')]);
                }
            }

            if (in_array($type, ['rating', 'scale', 'matrix'], true)) {
                $min = (int) ($q['scale']['min'] ?? 1);
                $max = (int) ($q['scale']['max'] ?? 5);
                $min = max(0, min(1, $min));
                $max = max($min + 1, min(10, $max));
                $item['scale'] = array_filter([
                    'min' => $min, 'max' => $max,
                    'min_label' => self::text($q['scale']['min_label'] ?? null),
                    'max_label' => self::text($q['scale']['max_label'] ?? null),
                ], fn ($v) => $v !== null);
                // competence: a low score signals a need; need: a high score signals a need.
                $item['mode'] = ($q['mode'] ?? 'competence') === 'need' ? 'need' : 'competence';
            }

            if (in_array($type, ['rating', 'scale', 'yes_no'], true)) {
                $item += self::skill($q);
            }

            if (! empty($q['show_if']['question']) && isset($ids[$q['show_if']['question']])) {
                $op = in_array($q['show_if']['op'] ?? 'equals', self::OPERATORS, true) ? $q['show_if']['op'] : 'equals';
                $item['show_if'] = ['question' => $q['show_if']['question'], 'op' => $op, 'value' => $q['show_if']['value'] ?? null];
            }

            $clean[] = array_filter($item, fn ($v) => $v !== null);
        }

        return $clean;
    }

    private static function items(array $items, string $prefix): array
    {
        $out = [];
        $seen = [];
        foreach (array_slice(array_values($items), 0, 40) as $n => $item) {
            $label = trim((string) (is_array($item) ? ($item['label'] ?? '') : $item));
            if ($label === '') {
                continue;
            }
            $id = is_array($item) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) ($item['id'] ?? '')) ? $item['id'] : $prefix.($n + 1);
            while (isset($seen[$id])) {
                $id .= 'x';
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'label' => Str::limit($label, 300, '')] + (is_array($item) ? self::skill($item) : []);
        }

        return $out;
    }

    private static function skill(array $source): array
    {
        return array_filter([
            'skill_id' => ! empty($source['skill_id']) && Str::isUuid($source['skill_id']) ? $source['skill_id'] : null,
            'skill_name' => self::text($source['skill_name'] ?? null),
        ]);
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 200, '');
    }

    /** Whether a question is shown for the given answers (conditional logic). */
    public static function visible(array $question, array $answers): bool
    {
        $rule = $question['show_if'] ?? null;
        if (! $rule) {
            return true;
        }
        $answer = $answers[$rule['question']] ?? null;
        $value = $rule['value'];

        return match ($rule['op']) {
            'answered' => self::filled($answer),
            'not_equals' => self::filled($answer) && ! self::matches($answer, $value),
            'includes', 'equals' => self::matches($answer, $value),
            'gte' => is_numeric($answer) && $answer >= (float) $value,
            'lte' => is_numeric($answer) && $answer <= (float) $value,
            default => true,
        };
    }

    private static function matches(mixed $answer, mixed $value): bool
    {
        return is_array($answer) ? in_array((string) $value, array_map('strval', $answer), true) : (string) $answer === (string) $value;
    }

    public static function filled(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }

    /**
     * Validates answers against the questions and returns only the answers to visible questions.
     *
     * @throws ValidationException
     */
    public static function validateAnswers(array $questions, array $answers): array
    {
        $clean = [];
        $errors = [];
        foreach ($questions as $q) {
            if ($q['type'] === 'section' || ! self::visible($q, $clean)) {
                continue;
            }
            $id = $q['id'];
            $value = $answers[$id] ?? null;
            if (! self::filled($value)) {
                if (! empty($q['required'])) {
                    $errors["answers.$id"] = __('This question is required.');
                }

                continue;
            }

            $optionIds = array_column($q['options'] ?? [], 'id');
            $scale = $q['scale'] ?? ['min' => 1, 'max' => 5];
            $ok = match ($q['type']) {
                'short_text' => is_string($value) && mb_strlen($value) <= 500,
                'long_text' => is_string($value) && mb_strlen($value) <= 5000,
                'single', 'dropdown' => in_array($value, $optionIds, true),
                'multiple' => is_array($value) && ! array_diff($value, $optionIds) && count($value) <= ($q['max_select'] ?? count($optionIds)),
                'ranking' => is_array($value) && ! array_diff($value, $optionIds) && count(array_unique($value)) === count($value),
                'yes_no' => in_array($value, ['yes', 'no'], true),
                'rating', 'scale' => is_numeric($value) && $value >= $scale['min'] && $value <= $scale['max'],
                'nps' => is_numeric($value) && $value >= 0 && $value <= 10,
                'number' => is_numeric($value) && abs((float) $value) < 1e9,
                'date' => is_string($value) && strtotime($value) !== false,
                'matrix' => is_array($value) && self::validMatrix($q, $value),
                default => false,
            };
            if (! $ok) {
                $errors["answers.$id"] = __('This answer is not valid.');

                continue;
            }
            $clean[$id] = match ($q['type']) {
                'rating', 'scale', 'nps' => (int) $value,
                'number' => (float) $value,
                'short_text', 'long_text' => trim($value),
                'multiple', 'ranking' => array_values($value),
                'matrix' => array_map('intval', array_intersect_key($value, array_flip(array_column($q['rows'], 'id')))),
                default => $value,
            };
            if ($q['type'] === 'matrix' && ! empty($q['required']) && count($clean[$id]) < count($q['rows'])) {
                $errors["answers.$id"] = __('Please answer every statement.');
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private static function validMatrix(array $q, array $value): bool
    {
        $rows = array_column($q['rows'], 'id');
        $scale = $q['scale'] ?? ['min' => 1, 'max' => 5];
        foreach ($value as $row => $v) {
            if (! in_array((string) $row, $rows, true) || ! is_numeric($v) || $v < $scale['min'] || $v > $scale['max']) {
                return false;
            }
        }

        return true;
    }
}
