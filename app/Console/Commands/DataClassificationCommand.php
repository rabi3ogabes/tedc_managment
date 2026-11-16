<?php

namespace App\Console\Commands;

use App\Ops\DataClassification;
use Illuminate\Console\Command;

class DataClassificationCommand extends Command
{
    protected $signature = 'tedc:data-classification {--write : write docs/security/data-classification.md}';

    protected $description = 'Print or write the data classification register from the live schema (Phase 17)';

    public function handle(DataClassification $c): int
    {
        $md = $c->markdown();
        if ($this->option('write')) {
            $path = base_path('../docs/security/data-classification.md');
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $md);
            $this->info("written: {$path}");

            return self::SUCCESS;
        }
        $this->line($md);

        return self::SUCCESS;
    }
}
