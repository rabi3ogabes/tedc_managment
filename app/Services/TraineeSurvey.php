<?php

namespace App\Services;

use App\Models\EvaluationForm;
use App\Services\NeedsSurveys\SurveySchema;
use App\Support\EvaluationTemplates;

/**
 * The trainee form — the programme survey a trainee answers about a programme (docs/saber/08-centre-forms.md). Its questions are the
 * satisfaction form's (the centre's form by default, EvaluationTemplates::trainee). Answers become one rating per statement
 * («trainer.clarity» → 1–5) plus the overall rating mapped onto the same scale, so the existing satisfaction reports, alerts and exports
 * keep working; the satisfaction score is the mean of the statements, as a percentage of the top of the scale.
 */
class TraineeSurvey
{
    public function __construct(private readonly EvaluationService $evaluations) {}

    /** @return array{title: string, intro: string, questions: list<array<string, mixed>>} */
    public function form(): array
    {
        $this->evaluations->ensureDefaults();
        $form = EvaluationForm::where('kind', 'satisfaction')->orderByDesc('is_default')->first();
        $template = EvaluationTemplates::trainee();
        $en = app()->getLocale() === 'en';

        return [
            'title' => $en ? ($form?->title_en ?: $template['title_en']) : ($form?->title_ar ?: $template['title_ar']),
            'intro' => (string) ($form?->settings[$en ? 'intro_en' : 'intro_ar'] ?? $template[$en ? 'intro_en' : 'intro_ar']),
            'questions' => $form?->questions ?: SurveySchema::normalize($template['questions']),
        ];
    }

    /**
     * Validated answers → what is stored on the evaluation.
     *
     * @param  array<string, mixed>  $answers
     * @return array{ratings: array<string, int>, comments: ?string, satisfaction: ?float, answers: array<string, mixed>}
     */
    public function record(array $answers): array
    {
        $questions = $this->form()['questions'];
        $clean = SurveySchema::validateAnswers($questions, $answers);
        $ratings = [];
        $shares = [];
        $comments = [];
        foreach ($questions as $q) {
            $v = $clean[$q['id']] ?? null;
            if ($v === null) {
                continue;
            }
            $max = (int) ($q['scale']['max'] ?? 5);
            if ($q['type'] === 'matrix') {
                foreach ($v as $row => $x) {
                    $ratings["{$q['id']}.{$row}"] = (int) $x;
                    $shares[] = $x / max(1, $max);
                }
            } elseif (in_array($q['type'], ['rating', 'scale'], true)) {
                $ratings[$q['id']] = (int) $v;
                $shares[] = $v / max(1, $max);
            } elseif ($q['type'] === 'single') {
                // The first option is the best (ممتاز … ضعيف): mapped onto 5 … 1.
                $ids = array_column($q['options'], 'id');
                $n = count($ids);
                $ratings[$q['id']] = (int) round(5 - array_search($v, $ids, true) * (4 / max(1, $n - 1)));
            } elseif (in_array($q['type'], ['short_text', 'long_text'], true)) {
                $comments[] = $v;
            }
        }

        return [
            'ratings' => $ratings, 'comments' => $comments ? implode("\n\n", $comments) : null, 'answers' => $clean,
            'satisfaction' => $shares ? round(array_sum($shares) / count($shares) * 100, 2) : ($ratings ? round(array_sum($ratings) / count($ratings) / 5 * 100, 2) : null),
        ];
    }

    /** The statement behind each stored rating key, for reports and exports. @return array<string, string> */
    public function labels(): array
    {
        $en = app()->getLocale() === 'en';
        $pick = fn (array $x, string $key) => $en ? ($x["{$key}_en"] ?? $x[$key]) : $x[$key];
        $out = [];
        foreach ($this->form()['questions'] as $q) {
            if ($q['type'] === 'matrix') {
                foreach ($q['rows'] as $row) {
                    $out["{$q['id']}.{$row['id']}"] = $pick($row, 'label');
                }
            } elseif (in_array($q['type'], ['rating', 'scale', 'single'], true)) {
                $out[$q['id']] = $pick($q, 'title');
            }
        }

        return $out + ($en ? ['content' => 'Content', 'trainer' => 'Trainer', 'organization' => 'Organisation', 'relevance' => 'Relevance to my work']
            : ['content' => 'المحتوى', 'trainer' => 'المدرب', 'organization' => 'التنظيم', 'relevance' => 'ملاءمة البرنامج لعملي']);
    }
}
