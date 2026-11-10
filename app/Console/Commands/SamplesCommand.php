<?php

namespace App\Console\Commands;

use Database\Seeders\DemoFeatureSamplesSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:samples')]
#[Description('Add sample data to every feature of the demo organisation, so each screen can be tried (safe to run again)')]
class SamplesCommand extends Command
{
    public function handle(): int
    {
        $seeder = new DemoFeatureSamplesSeeder;
        $seeder->setContainer(app())->setCommand($this);
        $seeder->run();
        foreach ($seeder->problems as $p) {
            $this->warn($p);
        }
        $this->info($seeder->problems ? count($seeder->problems).' part(s) could not be added; see above.' : 'Samples added for every feature.');

        return $seeder->problems ? self::FAILURE : self::SUCCESS;
    }
}
