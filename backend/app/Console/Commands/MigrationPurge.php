<?php

namespace App\Console\Commands;

use App\Migration\MigrationService;
use Illuminate\Console\Command;

class MigrationPurge extends Command
{
    protected $signature = 'tedc:migration-purge';

    protected $description = 'Deletes the uploaded data of migration batches past their retention period';

    public function handle(MigrationService $svc): int
    {
        $this->info('Batches purged: '.$svc->purgeExpired());

        return self::SUCCESS;
    }
}
