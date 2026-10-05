<?php

namespace App\Console\Commands;

use App\Services\IndividualNeedsService;
use App\Services\NeedsRuleEngine;
use Illuminate\Console\Command;

class NeedsDaily extends Command
{
    protected $signature = 'tedc:needs-daily';

    protected $description = 'Run the needs rules and auto-approve individual needs a manager did not decide in time';

    public function handle(NeedsRuleEngine $rules, IndividualNeedsService $needs): int
    {
        $r = $rules->run();
        $this->info("Rules: {$r['created']} new need(s). Auto-approved: {$needs->autoApprove()}.");

        return self::SUCCESS;
    }
}
