<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportRunService;
use App\Services\Reports\ReportScheduleService;
use Illuminate\Console\Command;

class ReportsRun extends Command
{
    protected $signature = 'tedc:reports-run';

    protected $description = 'Produces queued report files and sends scheduled reports that are due';

    public function handle(ReportRunService $runs, ReportScheduleService $schedules): int
    {
        $this->info('Queued runs: '.$runs->processQueue().'. Schedules: '.$schedules->runDue().'.');

        return self::SUCCESS;
    }
}
