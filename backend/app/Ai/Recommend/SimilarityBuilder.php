<?php

namespace App\Ai\Recommend;

use App\Models\ItemSimilarity;
use App\Models\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** "People who completed this also completed that": item–item cosine similarity from who finished what, computed in SQL and PHP (no external service). */
class SimilarityBuilder
{
    /** @return int pairs stored */
    public function build(int $minSupport = 2, int $keep = 20): int
    {
        $rows = Registration::whereIn('status', [Registration::STATUS_COMPLETED, Registration::STATUS_APPROVED])->whereNotNull('employee_id')->get(['employee_id', 'program_id', 'status']);
        $by = $rows->groupBy('employee_id')->map(fn ($g) => $g->pluck('program_id')->unique()->values()->all());
        $count = [];
        $co = [];
        foreach ($by as $programs) {
            foreach ($programs as $a) {
                $count[$a] = ($count[$a] ?? 0) + 1;
                foreach ($programs as $b) {
                    if ($a !== $b) {
                        $co[$a][$b] = ($co[$a][$b] ?? 0) + 1;
                    }
                }
            }
        }
        $now = now();
        $stored = 0;
        DB::transaction(function () use ($co, $count, $minSupport, $keep, $now, &$stored) {
            ItemSimilarity::where('item_type', 'program')->delete();
            foreach ($co as $a => $others) {
                $scored = [];
                foreach ($others as $b => $n) {
                    if ($n >= $minSupport) {
                        $scored[$b] = ['score' => round($n / sqrt($count[$a] * $count[$b]), 4), 'support' => $n];
                    }
                }
                uasort($scored, fn ($x, $y) => $y['score'] <=> $x['score']);
                foreach (array_slice($scored, 0, $keep, true) as $b => $v) {
                    ItemSimilarity::create(['id' => (string) Str::uuid(), 'item_type' => 'program', 'item_id' => $a, 'other_id' => $b, 'score' => $v['score'], 'support' => $v['support'], 'computed_at' => $now]);
                    $stored++;
                }
            }
        });

        return $stored;
    }
}
