<?php

namespace App\Ai\Adaptive;

use App\Models\AssessmentAttempt;
use App\Models\AttemptAnswerGrade;
use App\Models\LearnerMastery;
use App\Services\Assessment\QuestionTypes;

/**
 * How well a learner has mastered each competency, from the questions they answered. Every graded assessment adds evidence:
 * the share of that competency's questions answered correctly moves the mastery value (an exponential moving average, so recent work counts more).
 */
class MasteryService
{
    /** @return array<string, float> skill id => mastery after this attempt */
    public function record(AssessmentAttempt $at): array
    {
        if (! $at->registration_id) {
            return [];
        }
        $earned = [];
        $max = [];
        $grades = AttemptAnswerGrade::where('attempt_id', $at->id)->get()->keyBy('question_id');
        foreach ($at->questions as $item) {
            $skills = array_filter((array) ($item['skill_ids'] ?? []));
            if (! $skills) {
                continue;
            }
            $r = QuestionTypes::get($item['type'])->grade($item['payload'], ($at->answers ?? [])[$item['id']] ?? null);
            $ratio = $r['manual'] ? (isset($grades[$item['id']]) && $item['points'] > 0 ? $grades[$item['id']]->points_awarded / $item['points'] : null) : $r['ratio'];
            if ($ratio === null) {
                continue;
            }
            foreach ($skills as $sk) {
                $earned[$sk] = ($earned[$sk] ?? 0) + $ratio * $item['points'];
                $max[$sk] = ($max[$sk] ?? 0) + $item['points'];
            }
        }
        $out = [];
        foreach ($max as $sk => $m) {
            if ($m <= 0) {
                continue;
            }
            $observed = max(0.0, min(1.0, $earned[$sk] / $m));
            $row = LearnerMastery::firstOrNew(['registration_id' => $at->registration_id, 'skill_id' => $sk]);
            $row->mastery = $row->exists ? round($row->mastery + 0.5 * ($observed - $row->mastery), 4) : round($observed, 4);
            $row->evidence_count = ((int) $row->evidence_count) + 1;
            $row->save();
            $out[$sk] = (float) $row->mastery;
        }

        return $out;
    }
}
