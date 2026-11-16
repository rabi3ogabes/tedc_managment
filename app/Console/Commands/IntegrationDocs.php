<?php

namespace App\Console\Commands;

use App\Integrations\Contracts\IntegrationGuide;
use Illuminate\Console\Command;

class IntegrationDocs extends Command
{
    protected $signature = 'tedc:integration-docs {--check : Only check that the guides are current (exit 1 if not)}';

    protected $description = 'Writes docs/integrations/*.md, the go-live guide of each Ministry system, from its shipped contract';

    public function handle(IntegrationGuide $guide): int
    {
        $dir = base_path('../docs/integrations');
        $stale = [];
        foreach (IntegrationGuide::GUIDES as $file => $key) {
            $path = "{$dir}/{$file}.md";
            $text = $guide->render($key);
            if ($this->option('check')) {
                if (! is_file($path) || file_get_contents($path) !== $text) {
                    $stale[] = $file;
                }

                continue;
            }
            @mkdir($dir, 0775, true);
            file_put_contents($path, $text);
            $this->line("written: docs/integrations/{$file}.md");
        }
        if ($stale !== []) {
            $this->error('Out of date: '.implode(', ', $stale).'. Run: php artisan tedc:integration-docs');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
