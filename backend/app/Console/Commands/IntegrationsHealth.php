<?php

namespace App\Console\Commands;

use App\Integrations\IntegrationManager;
use App\Models\Integration;
use Illuminate\Console\Command;

class IntegrationsHealth extends Command
{
    protected $signature = 'tedc:integrations-health';

    protected $description = 'Checks the health of every enabled integration and runs the scheduled syncs that are due';

    public function handle(IntegrationManager $hub): int
    {
        foreach (Integration::where('enabled', true)->get() as $i) {
            $r = $hub->check($i->key);
            $this->line("{$i->key}: {$r['health']}");
        }

        return self::SUCCESS;
    }
}
