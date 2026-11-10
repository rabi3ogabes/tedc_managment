<?php

namespace App\Console\Commands;

use App\Services\Communication\MinistryExporter;
use Illuminate\Console\Command;

class MinistryPush extends Command
{
    protected $signature = 'tedc:ministry-push';

    protected $description = 'Sends news and events flagged for the Ministry website, and retries the ones that failed';

    public function handle(MinistryExporter $exporter): int
    {
        $r = $exporter->run();
        $this->info("Ministry website: {$r['sent']} sent, {$r['failed']} given up.");

        return self::SUCCESS;
    }
}
