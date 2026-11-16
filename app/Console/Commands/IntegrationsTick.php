<?php

namespace App\Console\Commands;

use App\Integrations\EventBus;
use App\Integrations\IntegrationManager;
use Illuminate\Console\Command;

class IntegrationsTick extends Command
{
    protected $signature = 'tedc:integrations-tick';

    protected $description = 'Delivers due webhook events and removes old integration logs and events';

    public function handle(EventBus $bus, IntegrationManager $hub): int
    {
        $r = $bus->deliverDue();
        $this->info("Webhooks: {$r['delivered']} delivered, {$r['retried']} to retry, {$r['dead']} dead.");
        if (now()->minute === 0) {
            $hub->prune();
            $bus->prune();
        }

        return self::SUCCESS;
    }
}
