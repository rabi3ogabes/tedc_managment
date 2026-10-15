<?php

namespace App\Services\Surveys;

/**
 * Evaluates a survey's logic against a set of answers: condition groups (all / any) on questions and page
 * breaks, and jumps that skip ahead to a later question or end the survey. The same walk drives server-side
 * validation and the studio's logic preview, so what the respondent sees is exactly what is validated.
 */
class LogicEngine
{
    /**
     * Walks the questions in order and calls `$visit($question)` for every visible answerable question.
     * `$visit` returns the (cleaned) answer value, or null when unanswered. Conditions are evaluated only
     * against the answers of questions already visited, so an answer to a hidden question never counts,
     * and jumps are decided on the value `$visit` returned.
     *
     * @return array{visible: list<string>, pages: list<list<string>>, ended: bool, answers: array<string, mixed>}
     */
    public static function walk(array $questions, callable $visit): array
    {
        $visible = [];
        $pages = [[]];
        $answers = [];
        $skipTo = null;
        $pageHidden = false;
        $ended = false;

        foreach ($questions as $q) {
            if ($ended) {
                break;
            }
            if ($skipTo !== null) {
                if ($q['id'] !== $skipTo) {
                    continue;
                }
                $skipTo = null;
            }
            if ($q['type'] === 'page_break') {
                $pageHidden = ! self::groupMet($q['visible_if'] ?? null, $answers);
                if (! $pageHidden) {
                    $visible[] = $q['id'];
                    $pages[] = [];
                }

                continue;
            }
            if ($pageHidden || ! self::groupMet($q['visible_if'] ?? null, $answers)) {
                continue;
            }
            $visible[] = $q['id'];
            $pages[array_key_last($pages)][] = $q['id'];
            if (in_array($q['type'], SurveyDefinition::NO_ANSWER, true)) {
                continue;
            }
            $value = $visit($q);
            if (! self::filled($value)) {
                continue;
            }
            $answers[$q['id']] = $value;
            if (empty($q['jumps'])) {
                continue;
            }
            foreach ($q['jumps'] as $jump) {
                if (self::conditionMet(['op' => $jump['op'], 'value' => $jump['value'] ?? null], $value)) {
                    if ($jump['to'] === 'end') {
                        $ended = true;
                    } else {
                        $skipTo = $jump['to'];
                    }
                    break;
                }
            }
        }

        return ['visible' => $visible, 'pages' => array_values(array_filter($pages)), 'ended' => $ended, 'answers' => $answers];
    }

    /** Which questions a respondent with these answers sees (for the studio preview and the renderers). */
    public static function evaluate(array $questions, array $answers): array
    {
        return self::walk($questions, fn (array $q) => $answers[$q['id']] ?? null);
    }

    /** A condition group: {match: all|any, conditions: [{question, op, value}]}. Absent group = always visible. */
    public static function groupMet(?array $group, array $answers): bool
    {
        if (! $group || empty($group['conditions'])) {
            return true;
        }
        $results = array_map(fn ($c) => self::conditionMet($c, $answers[$c['question']] ?? null), $group['conditions']);

        return ($group['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    public static function conditionMet(array $condition, mixed $answer): bool
    {
        $value = $condition['value'] ?? null;

        return match ($condition['op'] ?? 'equals') {
            'answered' => self::filled($answer),
            'not_answered' => ! self::filled($answer),
            'equals', 'includes' => self::matches($answer, $value),
            'not_equals', 'not_includes' => self::filled($answer) && ! self::matches($answer, $value),
            'gt' => is_numeric($answer) && (float) $answer > (float) $value,
            'gte' => is_numeric($answer) && (float) $answer >= (float) $value,
            'lt' => is_numeric($answer) && (float) $answer < (float) $value,
            'lte' => is_numeric($answer) && (float) $answer <= (float) $value,
            'contains' => is_string($answer) && $value !== null && $value !== '' && mb_stripos($answer, (string) $value) !== false,
            default => true,
        };
    }

    private static function matches(mixed $answer, mixed $value): bool
    {
        if (is_array($answer)) {
            // A matrix answer is keyed by row: compare its values; a choice list compares its members.
            $haystack = array_map('strval', array_is_list($answer) ? $answer : array_values($answer));

            return in_array((string) $value, $haystack, true);
        }

        return $answer !== null && (string) $answer === (string) $value;
    }

    public static function filled(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }
}
