<?php

namespace App\Services\Surveys;

use App\Models\Survey;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The question model of the survey engine (Survey Studio v2): cleans a definition coming from the
 * studio, validates its logic references and computes the checksum that decides whether a publish
 * creates a new version.
 *
 * Question: {id, type, title, title_en?, description?, description_en?, required,
 *            options?[{id,label,label_en?,skill_id?,skill_name?}], rows?[{id,label,...}], scale?{min,max,min_label,max_label},
 *            mode?, max_select?, min_select?, max_length?, min?, max?, max_files?, max_mb?, accept?[],
 *            visible_if?{match: all|any, conditions[{question, op, value}]}, jumps?[{op, value, to}]}
 */
class SurveyDefinition
{
    public const TYPES = ['section', 'instructions', 'page_break', 'short_text', 'long_text', 'email', 'phone', 'number', 'date', 'time',
        'single', 'multiple', 'dropdown', 'yes_no', 'rating', 'scale', 'likert', 'nps', 'matrix', 'ranking', 'file_upload'];

    /** Types that display something but take no answer. */
    public const NO_ANSWER = ['section', 'instructions', 'page_break'];

    public const CHOICE_TYPES = ['single', 'multiple', 'dropdown', 'ranking', 'likert'];

    public const SCALED_TYPES = ['rating', 'scale', 'matrix'];

    /** Types whose single value can trigger a jump. */
    public const JUMP_TYPES = ['single', 'dropdown', 'yes_no', 'rating', 'scale', 'nps', 'number', 'likert'];

    public const OPERATORS = ['equals', 'not_equals', 'includes', 'not_includes', 'gt', 'gte', 'lt', 'lte', 'contains', 'answered', 'not_answered'];

    public const ACCEPT = ['pdf', 'image', 'doc', 'xls', 'ppt', 'zip', 'audio', 'video'];

    public const MAX_QUESTIONS = 200;

    /** What each type is, for the studio palette and the renderers. */
    public static function catalog(): array
    {
        $t = fn (string $type, string $answer, string $ar, string $en, array $flags = []) => ['type' => $type, 'answer' => $answer, 'label' => ['ar' => $ar, 'en' => $en]] + $flags + [
            'options' => in_array($type, self::CHOICE_TYPES, true), 'rows' => $type === 'matrix', 'scale' => in_array($type, self::SCALED_TYPES, true),
            'jumps' => in_array($type, self::JUMP_TYPES, true), 'answerable' => ! in_array($type, self::NO_ANSWER, true),
        ];

        return [
            $t('section', 'none', 'عنوان قسم', 'Section heading'),
            $t('instructions', 'none', 'تعليمات', 'Instructions'),
            $t('page_break', 'none', 'فاصل صفحة', 'Page break'),
            $t('short_text', 'text', 'نص قصير', 'Short text'),
            $t('long_text', 'text', 'نص طويل', 'Long text'),
            $t('email', 'text', 'بريد إلكتروني', 'E-mail'),
            $t('phone', 'text', 'رقم هاتف', 'Phone number'),
            $t('number', 'number', 'رقم', 'Number'),
            $t('date', 'text', 'تاريخ', 'Date'),
            $t('time', 'text', 'وقت', 'Time'),
            $t('single', 'choice', 'اختيار واحد', 'Single choice'),
            $t('multiple', 'choices', 'اختيار متعدد', 'Multiple choice'),
            $t('dropdown', 'choice', 'قائمة منسدلة', 'Dropdown'),
            $t('yes_no', 'choice', 'نعم / لا', 'Yes / No'),
            $t('rating', 'number', 'تقييم بالنجوم', 'Star rating'),
            $t('scale', 'number', 'مقياس رقمي', 'Numeric scale'),
            $t('likert', 'choice', 'مقياس ليكرت', 'Likert scale'),
            $t('nps', 'number', 'مؤشر الترويج (NPS)', 'Net promoter score'),
            $t('matrix', 'matrix', 'مصفوفة عبارات', 'Matrix'),
            $t('ranking', 'choices', 'ترتيب', 'Ranking'),
            $t('file_upload', 'files', 'رفع ملف', 'File upload'),
        ];
    }

    /** Cleans and validates a question list coming from the studio. */
    public static function normalize(array $questions): array
    {
        $answerable = array_filter($questions, fn ($q) => ! in_array($q['type'] ?? '', self::NO_ANSWER, true));
        if (! $answerable) {
            throw ValidationException::withMessages(['questions' => __('messages.surveys.no_questions')]);
        }
        if (count($questions) > self::MAX_QUESTIONS) {
            throw ValidationException::withMessages(['questions' => __('messages.surveys.too_many', ['max' => self::MAX_QUESTIONS])]);
        }

        $ids = [];
        $clean = [];
        $pendingJumps = [];
        foreach (array_values($questions) as $i => $q) {
            $type = $q['type'] ?? null;
            $title = trim((string) ($q['title'] ?? ''));
            if (! in_array($type, self::TYPES, true)) {
                throw ValidationException::withMessages(["questions.$i.type" => __('messages.surveys.unknown_type')]);
            }
            if ($title === '' && $type !== 'page_break') {
                throw ValidationException::withMessages(["questions.$i.title" => __('messages.surveys.title_required')]);
            }

            $id = preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) ($q['id'] ?? '')) ? $q['id'] : 'q'.Str::lower(Str::random(8));
            while (isset($ids[$id])) {
                $id = 'q'.Str::lower(Str::random(8));
            }
            $ids[$id] = $i;

            $item = [
                'id' => $id,
                'type' => $type,
                'title' => $title === '' ? null : Str::limit($title, 500, ''),
                'title_en' => self::text($q['title_en'] ?? null, 500),
                'description' => self::text($q['description'] ?? null, 2000),
                'description_en' => self::text($q['description_en'] ?? null, 2000),
                'required' => ! in_array($type, self::NO_ANSWER, true) && (bool) ($q['required'] ?? false),
            ];

            if (in_array($type, self::CHOICE_TYPES, true)) {
                $item['options'] = self::items($q['options'] ?? [], 'o', $type === 'likert' ? 11 : 60);
                if (count($item['options']) < 2) {
                    throw ValidationException::withMessages(["questions.$i.options" => __('messages.surveys.options_min')]);
                }
                if ($type === 'multiple') {
                    if (! empty($q['max_select'])) {
                        $item['max_select'] = max(1, min(count($item['options']), (int) $q['max_select']));
                    }
                    if (! empty($q['min_select'])) {
                        $item['min_select'] = max(1, min($item['max_select'] ?? count($item['options']), (int) $q['min_select']));
                    }
                }
                if (in_array($type, ['single', 'multiple'], true) && ! empty($q['other'])) {
                    $item['other'] = true; // a free-text "other" option
                }
            }

            if ($type === 'matrix') {
                $item['rows'] = self::items($q['rows'] ?? [], 'r', 40);
                if (! $item['rows']) {
                    throw ValidationException::withMessages(["questions.$i.rows" => __('messages.surveys.rows_min')]);
                }
            }

            if (in_array($type, self::SCALED_TYPES, true)) {
                $min = max(0, min(1, (int) ($q['scale']['min'] ?? 1)));
                $max = max($min + 1, min(10, (int) ($q['scale']['max'] ?? 5)));
                $item['scale'] = array_filter([
                    'min' => $min, 'max' => $max,
                    'min_label' => self::text($q['scale']['min_label'] ?? null), 'max_label' => self::text($q['scale']['max_label'] ?? null),
                ], fn ($v) => $v !== null);
                // competence: a low score signals a need; need: a high score signals a need (training-needs analysis).
                $item['mode'] = ($q['mode'] ?? 'competence') === 'need' ? 'need' : 'competence';
            }

            if (in_array($type, ['rating', 'scale', 'yes_no', 'likert'], true)) {
                $item += self::skill($q);
            }

            if (in_array($type, ['short_text', 'long_text'], true)) {
                $cap = $type === 'short_text' ? 500 : 5000;
                $item['max_length'] = isset($q['max_length']) ? max(1, min($cap, (int) $q['max_length'])) : $cap;
                $item['placeholder'] = self::text($q['placeholder'] ?? null);
            }

            if ($type === 'number') {
                $item['min'] = isset($q['min']) && is_numeric($q['min']) ? (float) $q['min'] : null;
                $item['max'] = isset($q['max']) && is_numeric($q['max']) ? (float) $q['max'] : null;
                if ($item['min'] !== null && $item['max'] !== null && $item['min'] > $item['max']) {
                    throw ValidationException::withMessages(["questions.$i.max" => __('messages.surveys.range_invalid')]);
                }
            }

            if ($type === 'file_upload') {
                $item['max_files'] = max(1, min(5, (int) ($q['max_files'] ?? 1)));
                $item['max_mb'] = max(1, min(25, (int) ($q['max_mb'] ?? 10)));
                $accept = array_values(array_intersect(self::ACCEPT, array_map('strval', (array) ($q['accept'] ?? []))));
                $item['accept'] = $accept ?: ['pdf', 'image'];
            }

            // Conditional display: a group of conditions on earlier questions (the legacy single `show_if` is accepted).
            $rule = $q['visible_if'] ?? (! empty($q['show_if']['question']) ? ['match' => 'all', 'conditions' => [$q['show_if']]] : null);
            if (is_array($rule) && ! empty($rule['conditions'])) {
                $conditions = [];
                foreach (array_slice(array_values((array) $rule['conditions']), 0, 10) as $c) {
                    $ref = (string) ($c['question'] ?? '');
                    if (! isset($ids[$ref]) || $ref === $id) {
                        throw ValidationException::withMessages(["questions.$i.visible_if" => __('messages.surveys.logic_reference')]);
                    }
                    $conditions[] = ['question' => $ref, 'op' => in_array($c['op'] ?? 'equals', self::OPERATORS, true) ? $c['op'] : 'equals', 'value' => $c['value'] ?? null];
                }
                $item['visible_if'] = ['match' => ($rule['match'] ?? 'all') === 'any' ? 'any' : 'all', 'conditions' => $conditions];
            }

            // Jumps: when this answer matches, continue at a later question (or end the survey).
            if (in_array($type, self::JUMP_TYPES, true) && ! empty($q['jumps'])) {
                $jumps = [];
                foreach (array_slice(array_values((array) $q['jumps']), 0, 10) as $j) {
                    $to = (string) ($j['to'] ?? '');
                    if ($to === '') {
                        continue;
                    }
                    $jumps[] = ['op' => in_array($j['op'] ?? 'equals', self::OPERATORS, true) ? $j['op'] : 'equals', 'value' => $j['value'] ?? null, 'to' => $to];
                }
                if ($jumps) {
                    $item['jumps'] = $jumps;
                    $pendingJumps[$i] = $id;
                }
            }

            $clean[] = array_filter($item, fn ($v) => $v !== null);
        }

        // Jump targets must be later questions (or "end"); checked once every id is known.
        foreach ($pendingJumps as $i => $id) {
            foreach ($clean[$i]['jumps'] as $j) {
                if ($j['to'] !== 'end' && (! isset($ids[$j['to']]) || $ids[$j['to']] <= $i)) {
                    throw ValidationException::withMessages(["questions.$i.jumps" => __('messages.surveys.jump_target')]);
                }
            }
        }

        return $clean;
    }

    /** The checksum of a definition: identical questions give identical checksums whatever the key order. */
    public static function checksum(array $questions): string
    {
        return hash('sha256', json_encode(self::canonical($questions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonical'], $value);
        }
        ksort($value);

        return array_map([self::class, 'canonical'], $value);
    }

    /** Respondent-facing and policy settings, with the engine's defaults. */
    public static function normalizeSettings(array $settings, Survey|array|null $current = null): array
    {
        $cur = $current instanceof Survey ? ($current->settings ?? []) : (array) ($current ?? []);
        $s = $settings + $cur;
        $fields = array_values(array_intersect(SurveyPrivacy::SEGMENT_FIELDS, array_map('strval', (array) ($s['segment_fields'] ?? []))));

        return [
            'edit_policy' => in_array($s['edit_policy'] ?? null, Survey::EDIT_POLICIES, true) ? $s['edit_policy'] : 'until_close',
            'requires_approval' => array_key_exists('requires_approval', $s) ? (bool) $s['requires_approval'] : true,
            'segment_fields' => $fields,
            'segment_threshold' => max(2, min(50, (int) ($s['segment_threshold'] ?? config('tedc.surveys.segment_threshold', 5)))),
            'one_per_page' => (bool) ($s['one_per_page'] ?? false),
            'progress' => array_key_exists('progress', $s) ? (bool) $s['progress'] : true,
            'notify' => array_key_exists('notify', $s) ? (bool) $s['notify'] : true,
            'ai_opt_in' => (bool) ($s['ai_opt_in'] ?? false),
            'welcome' => self::text($s['welcome'] ?? null, 2000),
            'welcome_en' => self::text($s['welcome_en'] ?? null, 2000),
            'thank_you' => self::text($s['thank_you'] ?? null, 1000),
            'thank_you_en' => self::text($s['thank_you_en'] ?? null, 1000),
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($s['accent'] ?? '')) ? Str::lower($s['accent']) : null,
        ];
    }

    private static function items(array $items, string $prefix, int $max): array
    {
        $out = [];
        $seen = [];
        foreach (array_slice(array_values($items), 0, $max) as $n => $item) {
            $label = trim((string) (is_array($item) ? ($item['label'] ?? '') : $item));
            if ($label === '') {
                continue;
            }
            $id = is_array($item) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) ($item['id'] ?? '')) ? $item['id'] : $prefix.($n + 1);
            while (isset($seen[$id])) {
                $id .= 'x';
            }
            $seen[$id] = true;
            $row = ['id' => $id, 'label' => Str::limit($label, 300, '')];
            if (is_array($item)) {
                if (($en = self::text($item['label_en'] ?? null, 300)) !== null) {
                    $row['label_en'] = $en;
                }
                $row += self::skill($item);
            }
            $out[] = $row;
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

    public static function text(mixed $value, int $max = 200): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
