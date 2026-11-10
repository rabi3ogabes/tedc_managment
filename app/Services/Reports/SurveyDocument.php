<?php

namespace App\Services\Reports;

/** Turns per-question statistics (SurveyAnalyzer::questionStats) and the raw answers into an exportable document: summary first, then the answers. */
class SurveyDocument
{
    /**
     * @param  array<int, array<string, mixed>>  $stats
     * @param  array<int, array<string, mixed>>  $questions  the question definitions (for the raw sheet)
     * @param  list<array{who: ?string, at: string, answers: array<string, mixed>}>  $raw
     */
    public static function build(string $title, string $subtitle, int $responses, array $stats, array $questions, array $raw, string $locale = 'ar'): array
    {
        $ar = $locale === 'ar';
        $L = fn (string $a, string $e) => $ar ? $a : $e;
        $sections = [['heading' => $L('الملخص', 'Summary'), 'paragraphs' => [$L('عدد الاستجابات: ', 'Responses: ').$responses]]];

        foreach ($stats as $s) {
            $sec = ['heading' => $s['title'], 'paragraphs' => [$L('أجاب: ', 'Answered: ').$s['answered']]];
            if (! empty($s['options'])) {
                $sec['bars'] = array_map(fn ($o) => ['label' => $o['label'], 'value' => (float) ($o['percent'] ?? $o['score'] ?? 0), 'max' => 100], $s['options']);
            } elseif (! empty($s['distribution'])) {
                $sec['paragraphs'][] = $L('المتوسط: ', 'Average: ').($s['average'] ?? '—').(isset($s['nps']) ? ' · NPS '.$s['nps'] : '');
                $total = max(1, array_sum(array_column($s['distribution'], 'value')));
                $sec['bars'] = array_map(fn ($d) => ['label' => (string) $d['label'], 'value' => round($d['value'] / $total * 100, 1), 'max' => 100], $s['distribution']);
            } elseif (! empty($s['rows'])) {
                $sec['table'] = ['head' => [$L('البند', 'Statement'), $L('المتوسط', 'Average'), $L('أجاب', 'Answered')], 'rows' => array_map(fn ($r) => [$r['label'], $r['average'], $r['answered']], $s['rows'])];
            } elseif (isset($s['average'])) {
                $sec['paragraphs'][] = $L('المتوسط: ', 'Average: ').$s['average'];
            } elseif (! empty($s['samples'])) {
                $sec['bullets'] = $s['samples'];
            }
            $sections[] = $sec;
        }

        $cols = array_values(array_filter($questions, fn ($q) => $q['type'] !== 'section'));
        $head = array_merge([$L('المستجيب', 'Respondent'), $L('التاريخ', 'Date')], array_map(fn ($q) => $q['title'], $cols));
        $rows = array_map(function ($r) use ($cols) {
            $cells = [$r['who'] ?? '—', $r['at']];
            foreach ($cols as $q) {
                $a = $r['answers'][$q['id']] ?? null;
                $cells[] = is_array($a) ? json_encode($a, JSON_UNESCAPED_UNICODE) : $a;
            }

            return $cells;
        }, $raw);
        $sections[] = ['heading' => $L('الإجابات', 'Answers'), 'table' => ['head' => $head, 'rows' => $rows]];

        return ['title' => $title, 'subtitle' => $subtitle, 'sections' => $sections];
    }
}
