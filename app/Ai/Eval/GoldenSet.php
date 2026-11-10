<?php

namespace App\Ai\Eval;

use App\Ai\AiPolicy;
use App\Ai\Assistant\TraineeAssistant;
use App\Ai\Rag\Embedders;
use App\Ai\Rag\Retriever;
use App\Ai\Rag\Stores;
use App\Models\AiEvalRun;
use App\Models\HelpArticle;
use App\Models\Program;
use App\Models\User;

/**
 * Measures the assistant against the golden question set (resources/ai/golden-questions.json): for the answerable
 * questions, whether a right source is among the first 3 and 5 found and whether the answer cites one; for the
 * off-topic ones, whether it declines. The questions are asked as a person who is registered nowhere, so only what
 * everyone may see is searched; nothing is saved but the scores. Without --with-model the answers are the rule-based
 * ones, so the run is repeatable (CI); with it the configured model writes them.
 */
class GoldenSet
{
    public function __construct(private readonly Retriever $retriever, private readonly TraineeAssistant $assistant, private readonly Embedders $embedders, private readonly Stores $stores, private readonly AiPolicy $policy) {}

    /** @return list<array{id: string, lang: string, question: string, expected?: list<string>, off_topic?: bool}> */
    public function questions(): array
    {
        return json_decode((string) file_get_contents(resource_path('ai/golden-questions.json')), true)['questions'] ?? [];
    }

    public function run(bool $withModel = false, ?User $by = null): AiEvalRun
    {
        $asker = new User(['name' => 'Golden set']);
        $n = ['answerable' => 0, 'off_topic' => 0, 'hit3' => 0, 'hit5' => 0, 'cited' => 0, 'refused' => 0, 'false_refusals' => 0];
        $details = [];
        foreach ($this->questions() as $q) {
            $hits = $this->retriever->search($asker, $q['question'], 5);
            $found = array_map(fn ($h) => $h['source_type'].':'.$h['source_id'], $hits);
            $reply = $this->assistant->compose($asker, $q['question'], [], null, null, $withModel, $q['lang']);
            $refused = (bool) ($reply['meta']['refused'] ?? false);
            if (! empty($q['off_topic'])) {
                $n['off_topic']++;
                $n['refused'] += $refused ? 1 : 0;
                $details[] = ['id' => $q['id'], 'off_topic' => true, 'refused' => $refused, 'top' => $found[0] ?? null];

                continue;
            }
            $expected = $this->resolve($q['expected'] ?? []);
            $rank = null;
            foreach ($found as $i => $source) {
                if (in_array($source, $expected, true)) {
                    $rank = $i + 1;
                    break;
                }
            }
            $cited = array_map(fn ($c) => $c['type'].':'.($c['source_id'] ?? ''), $reply['citations']);
            $citedRight = ! $refused && array_intersect($cited, $expected) !== [];
            $n['answerable']++;
            $n['hit3'] += $rank !== null && $rank <= 3 ? 1 : 0;
            $n['hit5'] += $rank !== null && $rank <= 5 ? 1 : 0;
            $n['cited'] += $citedRight ? 1 : 0;
            $n['false_refusals'] += $refused ? 1 : 0;
            $details[] = ['id' => $q['id'], 'rank' => $rank, 'cited' => $citedRight, 'refused' => $refused, 'top' => $found[0] ?? null, 'expected' => $q['expected'] ?? []];
        }
        $share = fn (int $x, int $of) => $of > 0 ? round($x / $of, 4) : 0.0;
        $scores = ['hit_at_3' => $share($n['hit3'], $n['answerable']), 'hit_at_5' => $share($n['hit5'], $n['answerable']), 'citation_accuracy' => $share($n['cited'], $n['answerable']), 'refusal_accuracy' => $share($n['refused'], $n['off_topic'])];
        $min = $this->policy->all()['eval'];
        $passed = $scores['hit_at_3'] >= $min['hit_at_3'] && $scores['hit_at_5'] >= $min['hit_at_5'] && $scores['citation_accuracy'] >= $min['citation'] && $scores['refusal_accuracy'] >= $min['refusal'];

        return AiEvalRun::create($scores + [
            'embedder' => $this->embedders->active()->id(), 'store' => $this->stores->active()->name(), 'with_model' => $withModel, 'questions' => $n['answerable'] + $n['off_topic'],
            'answerable' => $n['answerable'], 'off_topic' => $n['off_topic'], 'false_refusals' => $n['false_refusals'], 'passed' => $passed, 'details' => $details, 'started_by' => $by?->id,
        ]);
    }

    /** "help:<slug>", "program:<code>" or "<type>:<id>" → "<type>:<id>" as stored in the index. @param  list<string>  $refs @return list<string> */
    private function resolve(array $refs): array
    {
        return array_values(array_filter(array_map(function (string $ref) {
            [$type, $key] = array_pad(explode(':', $ref, 2), 2, '');

            return match ($type) {
                'help' => ($id = HelpArticle::where('slug', $key)->value('id')) ? "help:{$id}" : null,
                'program' => ($id = Program::where('code', $key)->value('id')) ? "program:{$id}" : null,
                default => $key !== '' ? "{$type}:{$key}" : null,
            };
        }, $refs)));
    }
}
