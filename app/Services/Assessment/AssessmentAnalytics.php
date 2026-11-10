<?php

namespace App\Services\Assessment;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AttemptAnswerGrade;

/** Item analysis and score statistics of an assessment. */
class AssessmentAnalytics
{
    /** @return array<string, mixed> */
    public function forAssessment(Assessment $a): array
    {
        $attempts = AssessmentAttempt::with('registration.trainingGroup:id,code')->where('assessment_id', $a->id)->where('status', 'graded')->get();
        $scores = $attempts->pluck('score_percent')->map(fn ($v) => (float) $v);
        $n = $attempts->count();
        $buckets = array_fill(0, 10, 0);
        foreach ($scores as $s) {
            $buckets[min(9, (int) floor($s / 10))]++;
        }

        // Upper and lower 27 % of the scores, for the discrimination index.
        $sorted = $attempts->sortByDesc('score_percent')->values();
        $k = max(1, (int) round($n * 0.27));
        $upper = $sorted->take($k);
        $lower = $sorted->reverse()->take($k);

        $items = [];
        foreach ($attempts as $at) {
            foreach ($at->questions as $q) {
                $items[$q['id']]['stem_ar'] = $q['stem_ar'];
                $items[$q['id']]['stem_en'] = $q['stem_en'];
                $items[$q['id']]['type'] = $q['type'];
                $items[$q['id']]['points'] = $q['points'];
                $items[$q['id']]['ratios'][$at->id] = $this->ratio($q, $at);
                $answer = ($at->answers ?? [])[$q['id']] ?? null;
                if (is_string($answer) && in_array($q['type'], ['single_choice'], true)) {
                    $items[$q['id']]['distractors'][$answer] = ($items[$q['id']]['distractors'][$answer] ?? 0) + 1;
                }
            }
        }
        $itemRows = collect($items)->map(function ($it, $qid) use ($upper, $lower) {
            $ratios = collect($it['ratios']);
            $up = $upper->map(fn ($u) => $it['ratios'][$u->id] ?? null)->filter(fn ($v) => $v !== null);
            $lo = $lower->map(fn ($u) => $it['ratios'][$u->id] ?? null)->filter(fn ($v) => $v !== null);

            return ['question_id' => $qid, 'stem_ar' => $it['stem_ar'], 'stem_en' => $it['stem_en'], 'type' => $it['type'], 'answered' => $ratios->count(), 'difficulty_index' => round($ratios->avg(), 3),
                'discrimination' => $up->isNotEmpty() && $lo->isNotEmpty() ? round($up->avg() - $lo->avg(), 3) : null, 'distractors' => $it['distractors'] ?? []];
        })->sortBy('difficulty_index')->values()->all();

        return [
            'attempts' => $n, 'pass_rate' => $n ? round($attempts->where('passed', true)->count() / $n * 100, 1) : null, 'average' => $n ? round($scores->avg(), 1) : null, 'median' => $n ? $this->median($scores->all()) : null,
            'average_minutes' => $n ? round($attempts->filter(fn ($x) => $x->submitted_at)->avg(fn ($x) => $x->started_at->diffInSeconds($x->submitted_at) / 60), 1) : null, 'distribution' => $buckets, 'items' => $itemRows,
            'by_group' => $attempts->groupBy(fn ($x) => $x->registration->trainingGroup?->code ?? '—')->map(fn ($g, $code) => ['group' => $code, 'attempts' => $g->count(), 'average' => round($g->avg('score_percent'), 1), 'pass_rate' => round($g->where('passed', true)->count() / $g->count() * 100, 1)])->values()->all(),
        ];
    }

    private function ratio(array $q, AssessmentAttempt $at): float
    {
        $r = QuestionTypes::get($q['type'])->grade($q['payload'], ($at->answers ?? [])[$q['id']] ?? null);
        if ($r['manual']) {
            $g = AttemptAnswerGrade::where('attempt_id', $at->id)->where('question_id', $q['id'])->first();

            return $g && $q['points'] > 0 ? (float) $g->points_awarded / $q['points'] : 0.0;
        }

        return $r['ratio'];
    }

    private function median(array $v): float
    {
        sort($v);
        $c = count($v);

        return round($c % 2 ? $v[intdiv($c, 2)] : ($v[$c / 2 - 1] + $v[$c / 2]) / 2, 1);
    }
}
