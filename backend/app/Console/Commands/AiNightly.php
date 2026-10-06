<?php

namespace App\Console\Commands;

use App\Ai\AiGuard;
use App\Ai\Forecast\ForecastService;
use App\Ai\Forecast\RiskService;
use App\Ai\Rag\Indexer;
use App\Ai\Recommend\SimilarityBuilder;
use App\Services\FeatureSettings;
use Illuminate\Console\Command;

class AiNightly extends Command
{
    protected $signature = 'tedc:ai-nightly {--forecasts : also rebuild the next-year forecasts}';

    protected $description = 'Item similarity, risk flags, the retrieval index, log retention (and forecasts with --forecasts) — Phase 15';

    public function handle(SimilarityBuilder $sim, RiskService $risks, Indexer $indexer, AiGuard $guard, ForecastService $forecasts, FeatureSettings $features): int
    {
        if (! $features->enabled('ai')) {
            $this->line('AI is switched off.');

            return self::SUCCESS;
        }
        $this->line('similar pairs: '.$sim->build());
        $this->line('risks: '.json_encode($risks->run()));
        $this->line('index chunks: '.$indexer->all());
        $this->line('log lines pruned: '.$guard->prune());
        if ($this->option('forecasts') || now()->dayOfWeek === 1) {
            $this->line('forecasts: '.$forecasts->run());
        }

        return self::SUCCESS;
    }
}
