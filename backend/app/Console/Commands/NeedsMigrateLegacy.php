<?php

namespace App\Console\Commands;

use App\Needs\LegacyNeedsMigrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:needs-migrate-legacy {--dry-run : Only count what would be copied}')]
#[Description('Copy the needs of the old school-request screen into the needs-cycle requests (safe to repeat)')]
class NeedsMigrateLegacy extends Command
{
    public function handle(LegacyNeedsMigrator $migrator): int
    {
        $r = $migrator->run((bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? 'Would copy ' : 'Copied ')."{$r['created']} of {$r['found']} needs ({$r['skipped']} already moved).");

        return self::SUCCESS;
    }
}
