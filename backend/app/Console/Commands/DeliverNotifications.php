<?php

namespace App\Console\Commands;

use App\Services\Channels\NotificationChannels;
use App\Services\Notifications\NotificationScheduler;
use Illuminate\Console\Command;

class DeliverNotifications extends Command
{
    protected $signature = 'tedc:deliver-notifications {--limit=200}';

    protected $description = 'Sends scheduled notifications that are due and the queued e-mail and SMS copies of notifications';

    public function handle(NotificationChannels $channels, NotificationScheduler $scheduler): int
    {
        $due = $scheduler->runDue();   // scheduled and repeating notifications that fell due
        $r = $channels->process((int) $this->option('limit'));
        $this->info("Scheduled: {$due} sent. E-mail/SMS: {$r['sent']} sent, {$r['failed']} failed, {$r['skipped']} skipped.");

        return self::SUCCESS;
    }
}
