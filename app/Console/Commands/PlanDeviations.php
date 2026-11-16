<?php

namespace App\Console\Commands;

use App\Services\AnnualPlanService;
use Illuminate\Console\Command;

class PlanDeviations extends Command
{
    protected $signature = 'tedc:plan-deviations';

    protected $description = 'Flag deviations of active annual plans (late, under-filled, cancelled, postponed, unplanned) and notify planning staff';

    public function handle(AnnualPlanService $plans): int
    {
        $this->info('Notified '.$plans->notifyDeviations().' user(s).');

        return self::SUCCESS;
    }
}
