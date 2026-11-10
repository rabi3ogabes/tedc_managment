<?php

namespace App\Console\Commands;

use App\Services\QatarSchools;
use Illuminate\Console\Command;
use Throwable;

class SyncSchools extends Command
{
    protected $signature = 'tedc:schools-sync {--live : Read the ministry service now} {--save-bundle : Also refresh the copy shipped with the code}';

    protected $description = 'Imports the national school list (government, private, specialised) with map positions';

    public function handle(QatarSchools $schools): int
    {
        try {
            $rows = $this->option('live') || $this->option('save-bundle') ? $schools->fetchLive() : $schools->bundled();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('save-bundle')) {
            file_put_contents(QatarSchools::bundledPath(), json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $result = $schools->import($rows);
        $this->info("Schools: {$result['created']} added, {$result['updated']} updated ({$result['total']} in the list).");

        return self::SUCCESS;
    }
}
