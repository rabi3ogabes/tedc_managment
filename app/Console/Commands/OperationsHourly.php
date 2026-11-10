<?php

namespace App\Console\Commands;

use App\Services\AbsenceService;
use App\Services\LogisticsService;
use Illuminate\Console\Command;

class OperationsHourly extends Command
{
    protected $signature = 'tedc:operations-hourly';

    protected $description = 'Hourly operations: absence thresholds and overdue logistics requests';

    public function handle(AbsenceService $absence, LogisticsService $logistics): int
    {
        $this->info('Absence alerts: '.$absence->monitor().'. Overdue logistics: '.$logistics->escalateOverdue().'.');

        return self::SUCCESS;
    }
}
