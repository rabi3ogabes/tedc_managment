<?php

namespace App\Console\Commands;

use App\Services\NeedsIntakeService;
use Illuminate\Console\Command;

class NeedsCyclesDaily extends Command
{
    protected $signature = 'tedc:needs-cycles';

    protected $description = 'Remind about needs cycles that are about to close and close the ones past their deadline';

    public function handle(NeedsIntakeService $intake): int
    {
        $r = $intake->daily();
        $this->info("Reminded {$r['reminded']}, closed {$r['closed']}.");

        return self::SUCCESS;
    }
}
