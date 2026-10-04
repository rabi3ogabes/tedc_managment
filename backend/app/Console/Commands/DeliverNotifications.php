<?php

namespace App\Console\Commands;

use App\Services\Channels\NotificationChannels;
use Illuminate\Console\Command;

class DeliverNotifications extends Command
{
    protected $signature = 'tedc:deliver-notifications {--limit=200}';

    protected $description = 'Sends the queued e-mail and SMS copies of notifications';

    public function handle(NotificationChannels $channels): int
    {
        $r = $channels->process((int) $this->option('limit'));
        $this->info("E-mail/SMS: {$r['sent']} sent, {$r['failed']} failed, {$r['skipped']} skipped.");

        return self::SUCCESS;
    }
}
