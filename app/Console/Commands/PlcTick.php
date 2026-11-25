<?php

namespace App\Console\Commands;

use App\Services\Plc\WorkshopLifecycle;
use Illuminate\Console\Command;

/** PLC Phase 5: moves workshops by the clock (ready a day before, in progress at the first session, completed after the last). */
class PlcTick extends Command
{
    protected $signature = 'tedc:plc-tick';

    protected $description = 'Advance PLC workshops by the clock: ready, in progress, completed (with attendance locked), evaluations sent and the close.';

    public function handle(WorkshopLifecycle $lifecycle): int
    {
        $r = $lifecycle->tick();
        $this->info("ready {$r['ready']}, in progress {$r['in_progress']}, completed {$r['completed']}, closed {$r['closed']}.");

        return self::SUCCESS;
    }
}
