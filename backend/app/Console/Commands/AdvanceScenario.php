<?php

namespace App\Console\Commands;

use App\Support\Features;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Console\Command;

class AdvanceScenario extends Command
{
    protected $signature = 'tedc:scenario-advance';

    protected $description = 'Moves the presentation scenario forward with the calendar so today\'s session is always today';

    public function handle(): int
    {
        if (! Features::enabled('demo_scenarios')) {
            $this->info('The demo scenario is switched off (Settings → Features).');

            return self::SUCCESS;
        }
        $days = DemoScenarioSeeder::advance();
        $this->info($days > 0 ? "Scenario moved forward {$days} day(s)." : 'Scenario is current.');

        return self::SUCCESS;
    }
}
