<?php

namespace App\Console\Commands;

use App\Integrations\Ministry\SaeedTickets;
use App\Integrations\Ministry\SijilArchive;
use Illuminate\Console\Command;

class TicketsSync extends Command
{
    protected $signature = 'tedc:tickets-sync';

    protected $description = 'Sends waiting problem reports to Saaed, refreshes their status, and sends queued certificates to the Sijil archive';

    public function handle(SaeedTickets $tickets, SijilArchive $sijil): int
    {
        $t = $tickets->tick();
        $a = $sijil->run();
        $this->info("Tickets: {$t['sent']} sent, {$t['updated']} updated. Sijil: {$a['archived']} archived, {$a['failed']} failed.");

        return self::SUCCESS;
    }
}
