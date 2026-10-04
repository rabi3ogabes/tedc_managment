<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSampleKitsSeeder;
use Illuminate\Console\Command;

class BuildSampleKits extends Command
{
    protected $signature = 'tedc:sample-kits';

    protected $description = 'Builds the fifteen ready sample training kits (five in person, five online, five hybrid)';

    public function handle(DemoSampleKitsSeeder $seeder): int
    {
        foreach (array_keys(DemoSampleKitsSeeder::samples()) as $i) {
            $kit = $seeder->build($i + 1);
            $this->line(($kit?->code ?? '—').' '.($kit?->title_ar ?? ''));
        }

        return self::SUCCESS;
    }
}
