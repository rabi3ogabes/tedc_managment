<?php

namespace App\Console\Commands;

use App\Integrations\Ministry\SaeedTickets;
use App\Integrations\Ministry\SijilArchive;
use App\Services\Account\HrProfileRouting;
use Illuminate\Console\Command;

class TicketsSync extends Command
{
    protected $signature = 'tedc:tickets-sync';

    protected $description = 'Sends waiting problem reports to Saaed, refreshes their status, sends queued certificates to the Sijil archive and profile changes that waited for HR';

    public function handle(SaeedTickets $tickets, SijilArchive $sijil, HrProfileRouting $hr): int
    {
        $t = $tickets->tick();
        $a = $sijil->run();
        $h = $hr->tick();
        $this->info("Tickets: {$t['sent']} sent, {$t['updated']} updated. Sijil: {$a['archived']} archived, {$a['failed']} failed. HR changes: {$h['sent']} sent, {$h['waiting']} waiting.");

        return self::SUCCESS;
    }
}
