<?php

namespace App\Console\Commands;

use App\Integrations\IntegrationManager;
use App\Integrations\Ministry\HrSync;
use App\Integrations\Ministry\LicenceSync;
use App\Integrations\Ministry\NsisSync;
use App\Integrations\Ministry\QnedsPublisher;
use Illuminate\Console\Command;
use Throwable;

class MinistrySync extends Command
{
    protected $signature = 'tedc:ministry-sync {--all : run every sync now, whatever the hour}';

    protected $description = 'Hourly: HR and licences. Daily: NSIS. Monthly: QNEDS indicators. Each only when its system is switched on.';

    public function handle(IntegrationManager $hub, HrSync $hr, LicenceSync $licences, NsisSync $nsis, QnedsPublisher $qneds): int
    {
        $all = (bool) $this->option('all');
        $run = function (string $key, callable $fn) use ($hub) {
            if (! $hub->isReady($key)) {
                return;
            }
            try {
                $this->line("{$key}: ".json_encode($fn()));
            } catch (Throwable $e) {
                $this->warn("{$key}: ".$e->getMessage());
            }
        };
        if ($active = $hr->active()) {
            $run($active, fn () => $hr->run($active));
        }
        $run('licences', fn () => $licences->run());
        if ($all || (now()->hour === 3 && now()->minute < 30)) {
            $run('nsis', fn () => $nsis->run());
        }
        if ($all || (now()->day === 1 && now()->hour === 4)) {
            $run('qneds', fn () => $qneds->publish());
        }

        return self::SUCCESS;
    }
}
