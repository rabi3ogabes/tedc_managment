<?php

namespace App\Console\Commands;

use App\Security\AppKeyRotator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Moves everything locked with APP_KEY to a new key (see AppKeyRotator and docs/KEY-ROTATION.md). The new key comes from
 * TEDC_NEW_APP_KEY (or --new-key); `verify` is run on the new key itself, as APP_KEY. Prints counts only.
 */
#[Signature('tedc:rotate-app-key {--mode=dry : dry (count only), run (write) or verify (everything opens with this APP_KEY)} {--new-key= : the new key (default TEDC_NEW_APP_KEY)} {--seconds=240 : stop after this long; run again to continue} {--yes : do not ask before writing}')]
#[Description('Re-lock every APP_KEY-encrypted value and recompute the keyed fingerprints under a new key')]
class RotateAppKey extends Command
{
    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        $new = (string) ($this->option('new-key') ?: config('tedc.rotation.new_app_key'));
        if ($mode === 'verify' && $new === '') {
            $new = (string) config('app.key');
        }
        if ($mode === 'run' && ! $this->option('yes') && ! $this->confirm('This rewrites every encrypted value in the database under the new key. Take a database backup first. Continue?')) {
            return self::FAILURE;
        }

        try {
            $report = (new AppKeyRotator($new, $mode, (int) $this->option('seconds')))->handle();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report['columns'] as $column => $c) {
            $this->line(sprintf('  %-46s found %-6d rotated %-6d already new %-6d unreadable %-4d blocked %d', $column, $c['found'], $c['rotated'], $c['already_new'], $c['unreadable'], $c['blocked']));
        }
        foreach ($report['fingerprints'] as $name => $n) {
            $this->line("  fingerprints {$name}: {$n}");
        }
        $this->line(sprintf('Mode %s: found %d locked values — rotated (or to rotate) %d, already under the new key %d, unreadable %d, blocked %d.%s', $report['mode'], $report['found'], $report['rotated'], $report['already_new'], $report['unreadable'], $report['blocked'], $report['finished'] ? '' : ' STOPPED EARLY (time limit): run it again to continue.'));

        if (! $report['finished']) {
            return 2;
        }
        $report['complete'] ? $this->info('Complete: nothing is left under the old key.') : $this->warn('Not complete yet — see the counts above.');

        return $report['complete'] ? self::SUCCESS : self::FAILURE;
    }
}
