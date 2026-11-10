<?php

namespace App\Console\Commands;

use App\Ai\AiPolicy;
use App\Ai\Eval\GoldenSet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:ai-eval {--with-model : Let the configured model write the answers (otherwise the repeatable rule-based answers)}')]
#[Description('Measure the assistant against the golden question set: hit@3, hit@5, citation accuracy and refusal accuracy')]
class AiEval extends Command
{
    public function handle(GoldenSet $golden, AiPolicy $policy): int
    {
        $run = $golden->run((bool) $this->option('with-model'));
        $min = $policy->all()['eval'];
        $pct = fn (float $x) => number_format($x * 100, 1).'%';
        $this->table(['Measure', 'Score', 'Needed'], [
            ['hit@3', $pct($run->hit_at_3), $pct($min['hit_at_3'])],
            ['hit@5', $pct($run->hit_at_5), $pct($min['hit_at_5'])],
            ['Citation accuracy', $pct($run->citation_accuracy), $pct($min['citation'])],
            ['Refusal accuracy (off-topic)', $pct($run->refusal_accuracy), $pct($min['refusal'])],
        ]);
        $this->line("{$run->questions} questions ({$run->answerable} answerable, {$run->off_topic} off-topic), embedder {$run->embedder}, store {$run->store}; {$run->false_refusals} answerable questions were declined.");
        foreach (collect($run->details)->filter(fn ($d) => empty($d['off_topic']) ? ($d['rank'] === null || $d['rank'] > 3 || ! $d['cited']) : ! $d['refused']) as $d) {
            $this->line("  · {$d['id']}: ".(empty($d['off_topic']) ? 'rank '.($d['rank'] ?? '–').($d['cited'] ? '' : ', not cited') : 'answered an off-topic question'));
        }
        $run->passed ? $this->info('Passed.') : $this->error('Below the thresholds in Settings → AI.');

        return $run->passed ? self::SUCCESS : self::FAILURE;
    }
}
