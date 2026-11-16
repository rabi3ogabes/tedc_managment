<?php

namespace App\Services;

use App\Models\AssessmentAttempt;
use App\Models\Question;
use App\Models\Skill;
use App\Services\Assessment\KnowledgeService;
use App\Services\Assessment\QuestionTypes;

/** Pre-test against post-test: averages, the knowledge gain against its target, the spread of gains, per-skill gains and a plain-words significance hint. */
class ComparativeAnalysis
{
    public const TARGET_GAIN = 35.0;

    public function __construct(private readonly KnowledgeService $knowledge) {}

    /** @return array<string, mixed> */
    public function forProgram(string $programId, ?string $groupId = null): array
    {
        $rows = collect($this->knowledge->gain($programId, $groupId));
        $paired = $rows->filter(fn ($r) => $r['pre'] !== null && $r['post'] !== null)->values();
        $n = $paired->count();
        $pre = $n ? round((float) $paired->avg('pre'), 2) : null;
        $post = $n ? round((float) $paired->avg('post'), 2) : null;
        $gains = $paired->pluck('gain_percent')->filter(fn ($g) => $g !== null);
        $avgGain = $gains->isNotEmpty() ? round((float) $gains->avg(), 1) : null;

        $buckets = ['below_zero' => 0, 'zero_to_10' => 0, 'ten_to_25' => 0, 'twenty_five_to_50' => 0, 'above_50' => 0];
        foreach ($gains as $g) {
            $buckets[match (true) {
                $g < 0 => 'below_zero', $g < 10 => 'zero_to_10', $g < 25 => 'ten_to_25', $g < 50 => 'twenty_five_to_50', default => 'above_50'
            }]++;
        }

        return [
            'participants_with_both' => $n, 'pre_average' => $pre, 'post_average' => $post, 'average_gain_percent' => $avgGain, 'target_percent' => self::TARGET_GAIN,
            'target_met' => $avgGain !== null ? $avgGain >= self::TARGET_GAIN : null, 'distribution' => $buckets, 'rows' => $rows->all(),
            'significance' => $this->significance($paired->pluck('gain')->map(fn ($g) => (float) $g)->all()), 'skills' => $this->skills($programId, $groupId),
        ];
    }

    /** Paired differences: t statistic, an approximate two-sided p value and the effect size, explained in words. @param list<float> $diffs */
    public function significance(array $diffs): array
    {
        $n = count($diffs);
        if ($n < 3) {
            return ['n' => $n, 'enough' => false, 'level' => 'insufficient'];
        }
        $mean = array_sum($diffs) / $n;
        $sd = sqrt(array_sum(array_map(fn ($d) => ($d - $mean) ** 2, $diffs)) / ($n - 1));
        if ($sd < 1e-9) {
            return ['n' => $n, 'enough' => true, 'mean_difference' => round($mean, 2), 'effect_size' => null, 't' => null, 'p' => $mean != 0 ? 0.0 : 1.0, 'level' => $mean > 0 ? 'strong' : 'none'];
        }
        $t = $mean / ($sd / sqrt($n));
        $df = $n - 1;
        // A normal approximation of the Student t distribution (Hill): good enough for a hint, not for publication.
        $z = abs($t) * (1 - 1 / (4 * $df)) / sqrt(1 + $t * $t / (2 * $df));
        $p = min(1.0, 2 * (1 - $this->normalCdf($z)));
        $d = $mean / $sd;
        $level = $p < 0.01 ? 'strong' : ($p < 0.05 ? 'moderate' : 'none');

        return ['n' => $n, 'enough' => true, 'mean_difference' => round($mean, 2), 't' => round($t, 2), 'p' => round($p, 4), 'effect_size' => round($d, 2), 'effect' => abs($d) >= 0.8 ? 'large' : (abs($d) >= 0.5 ? 'medium' : (abs($d) >= 0.2 ? 'small' : 'negligible')), 'level' => $mean > 0 ? $level : 'none'];
    }

    private function normalCdf(float $z): float
    {
        // Abramowitz & Stegun 26.2.17
        $t = 1 / (1 + 0.2316419 * abs($z));
        $d = 0.3989423 * exp(-$z * $z / 2);
        $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));

        return $z >= 0 ? 1 - $p : $p;
    }

    /** Per competency: the average share of the mapped questions answered right in the pre-test and in the post-test. @return list<array<string, mixed>> */
    private function skills(string $programId, ?string $groupId): array
    {
        $attempts = AssessmentAttempt::with(['assessment:id,kind', 'registration:id,training_group_id'])->where('status', 'graded')
            ->whereHas('assessment', fn ($q) => $q->where('program_id', $programId)->whereIn('kind', ['pre_test', 'post_test']))->get()
            ->filter(fn ($a) => ! $groupId || $a->registration?->training_group_id === $groupId);
        $questionSkills = Question::whereIn('id', $attempts->flatMap(fn ($a) => array_column($a->questions, 'id'))->unique())->get()->mapWithKeys(fn ($q) => [$q->id => $q->skill_ids ?? []]);

        $acc = [];
        foreach ($attempts as $at) {
            foreach ($at->questions as $q) {
                foreach ($questionSkills[$q['id']] ?? [] as $skillId) {
                    $r = QuestionTypes::get($q['type'])->grade($q['payload'], ($at->answers ?? [])[$q['id']] ?? null);
                    if ($r['manual']) {
                        continue;
                    }
                    $acc[$skillId][$at->assessment->kind][] = $r['ratio'] * 100;
                }
            }
        }
        $names = Skill::whereIn('id', array_keys($acc))->get()->keyBy('id');

        return collect($acc)->map(function ($by, $id) use ($names) {
            $pre = isset($by['pre_test']) ? round(array_sum($by['pre_test']) / count($by['pre_test']), 1) : null;
            $post = isset($by['post_test']) ? round(array_sum($by['post_test']) / count($by['post_test']), 1) : null;

            return ['skill_id' => $id, 'name_ar' => $names[$id]->name_ar ?? '', 'name_en' => $names[$id]->name_en ?? '', 'pre' => $pre, 'post' => $post, 'gain' => $pre !== null && $post !== null ? round($post - $pre, 1) : null];
        })->sortByDesc('gain')->values()->all();
    }
}
