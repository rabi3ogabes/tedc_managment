<?php

namespace App\Console\Commands;

use App\Services\ImpactService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:dispatch-surveys')]
#[Description('Send due 30/60/90-day impact surveys and expire unanswered ones')]
class DispatchImpactSurveys extends Command
{
    public function handle(ImpactService $impact): int
    {
        $result = $impact->dispatchDue();
        $this->info("Sent {$result['sent']} surveys, expired {$result['expired']}.");

        return self::SUCCESS;
    }
}
