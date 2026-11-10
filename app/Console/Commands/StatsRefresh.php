<?php

namespace App\Console\Commands;

use App\Services\Cms\PublicStatsService;
use Illuminate\Console\Command;

class StatsRefresh extends Command
{
    protected $signature = 'tedc:stats-refresh';

    protected $description = 'Recomputes the statistics shown on the public homepage';

    public function handle(PublicStatsService $stats): int
    {
        $stats->refresh();
        $this->info('Public statistics refreshed.');

        return self::SUCCESS;
    }
}
