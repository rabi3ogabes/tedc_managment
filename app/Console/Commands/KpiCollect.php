<?php

namespace App\Console\Commands;

use App\Services\Kpi\KpiService;
use Illuminate\Console\Command;

class KpiCollect extends Command
{
    protected $signature = 'tedc:kpi-collect';

    protected $description = 'Measures the KPIs, stores a sample of each and warns administrators about breaches';

    public function handle(KpiService $kpi): int
    {
        $values = $kpi->collect();
        $this->info('KPIs measured: '.count($values));

        return self::SUCCESS;
    }
}
