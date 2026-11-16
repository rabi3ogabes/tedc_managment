<?php

namespace App\Console\Commands;

use App\Services\Content\CaliperService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:caliper-flush')]
#[Description('Send the queued IMS Caliper events')]
class CaliperFlush extends Command
{
    public function handle(CaliperService $caliper): int
    {
        $r = $caliper->flush();
        $this->info("Caliper: sent {$r['sent']}, failed {$r['failed']}.");

        return self::SUCCESS;
    }
}
