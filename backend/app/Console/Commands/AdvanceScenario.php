<?php

namespace App\Console\Commands;

use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Console\Command;

class AdvanceScenario extends Command
{
    protected $signature = 'tedc:scenario-advance';

    protected $description = 'Moves the presentation scenario forward with the calendar so today\'s session is always today';

    public function handle(): int
    {
        $days = DemoScenarioSeeder::advance();
        $this->info($days > 0 ? "Scenario moved forward {$days} day(s)." : 'Scenario is current.');

        return self::SUCCESS;
    }
}
