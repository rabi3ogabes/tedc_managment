<?php

namespace App\Console\Commands;

use App\Models\ErrorLog;
use App\Services\ErrorAutoFixer;
use App\Services\ErrorLogService;
use App\Services\ErrorLogSettings;
use App\Support\Features;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('tedc:self-heal')]
#[Description('Apply known remedies to open errors, close temporary ones that stopped, and prune old log entries')]
class SelfHeal extends Command
{
    public function handle(ErrorLogService $logs, ErrorAutoFixer $fixer, ErrorLogSettings $settings): int
    {
        $s = $settings->all();
        $fixed = 0;
        // In production the remedies are only suggested (shown in the error log) unless Settings → Features switches this on.
        $s['auto_fix'] = $s['auto_fix'] && Features::enabled('self_heal');
        if (! Features::enabled('self_heal')) {
            $this->info('Automatic fixing is switched off: remedies are only suggested (Settings → Features).');
        }

        // 1. A database that is behind the code is brought up to date.
        try {
            DB::connection()->getPdo();
            if ($s['auto_fix'] && $this->pendingMigrations()) {
                Artisan::call('migrate', ['--force' => true]);
                $this->info('Ran pending migrations.');
                $fixed++;
            }
        } catch (Throwable $e) {
            $this->warn('Database unreachable: '.$e->getMessage());
        }

        // 2. Open errors with a known remedy that were not tried yet (or failed earlier).
        if ($s['auto_fix']) {
            ErrorLog::where('status', 'open')->where('fix_attempts', '<', 3)->orderByDesc('last_seen_at')->limit(50)->get()->each(function (ErrorLog $l) use ($logs, $fixer, &$fixed) {
                if ($fixer->ruleFor($l) && $logs->tryFix($l)['fixed']) {
                    $fixed++;
                }
            });
        }

        // 3. Temporary connection errors that have not come back for half an hour are closed.
        $recovered = $s['auto_fix'] ? ErrorLog::where('status', 'open')->where('last_seen_at', '<', now()->subMinutes(30))->get()->filter(fn (ErrorLog $l) => $fixer->ruleFor($l) === 'transient') : collect();
        $recovered->each(fn (ErrorLog $l) => $l->update(['status' => 'fixed', 'auto_fixed' => true, 'resolved_at' => now(), 'note' => trim(($l->note ? $l->note."\n" : '').now()->format('Y-m-d H:i').' Closed automatically: it did not recur for 30 minutes.')]));

        // 4. Old entries go.
        $pruned = ErrorLog::where('status', '!=', 'open')->where('last_seen_at', '<', now()->subDays($s['retention_days']))->delete();

        $this->info("Applied {$fixed} remedies, closed {$recovered->count()} recovered, pruned {$pruned}.");

        return self::SUCCESS;
    }

    private function pendingMigrations(): bool
    {
        Artisan::call('migrate:status', ['--pending' => true]);

        return str_contains(Artisan::output(), 'Pending') || preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_\w+\s+\.+\s*Pending/', Artisan::output()) === 1;
    }
}
