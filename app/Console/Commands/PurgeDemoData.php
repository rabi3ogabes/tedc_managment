<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\DemoPurge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Deletes the demo data and keeps the platform setup, the official schools and the imported people (see DemoPurge). Without --force it only
 * counts. Settings → Data does the same from the browser.
 */
#[Signature('tedc:purge-demo
    {--force : Delete for real (without it: a dry run that only counts)}
    {--admin= : E-mail of the administrator to keep, created as Super Admin when missing (required with --force)}
    {--admin-name= : Name of that administrator when it is created}
    {--backup= : Before deleting, write every row that goes to this file (gzipped JSON lines), so it can be inspected or restored}')]
#[Description('Delete the demo data and keep only the platform setup, the official schools and the imported people (dry run unless --force)')]
class PurgeDemoData extends Command
{
    public function handle(DemoPurge $purge): int
    {
        $force = (bool) $this->option('force');
        $admin = strtolower(trim((string) $this->option('admin')));
        if ($force && ! filter_var($admin, FILTER_VALIDATE_EMAIL)) {
            $this->error('--admin=<e-mail> is required with --force: the administrator who will still be able to sign in.');

            return self::FAILURE;
        }
        if ($purge->importedPeople() === 0) {
            $this->error('No imported employees: import the Ministry extract first, or there would be nobody left.');

            return self::FAILURE;
        }
        $purge->forAdmin($admin);
        $plan = $purge->plan();
        $kept = $purge->kept();
        $this->info(($force ? 'Deleting' : 'Dry run — would delete').' '.number_format(array_sum($plan)).' rows in '.count($plan).' tables.');
        $this->table(['Table', 'Rows'], collect($plan)->map(fn ($n, $t) => [$t, number_format($n)])->values()->all());
        $this->line('Kept: '.number_format($kept['people']).' imported employees and their courses, '.number_format($kept['schools']).' schools, the platform setup ('.$kept['setup_tables'].' tables).');
        if (! $force) {
            $this->line('Nothing was changed. Run again with --force --admin=<e-mail> to delete.');

            return self::SUCCESS;
        }

        if ($path = (string) $this->option('backup')) {
            $this->info('Backup: '.number_format($purge->backup($path, array_keys($plan))).' rows written to '.$path.'.');
        }
        $existed = User::where('email', $admin)->exists();
        $user = $purge->ensureAdmin(trim((string) $this->option('admin-name')) ?: 'مدير النظام');
        if (! $existed) {
            $this->info("Administrator {$admin} created (no password yet).");
        }
        $result = $purge->purge(array_keys($plan));
        foreach ($result['messages'] as $m) {
            $this->warn($m);
        }
        if ($result['left'] !== []) {
            $this->error('Could not empty: '.implode(', ', $result['left']).'. Nothing else is affected; see the messages above.');

            return self::FAILURE;
        }
        $purge->switchOffDemoFeatures($user);
        if ($n = $purge->removeFromSupabase($result['removed_emails'])) {
            $this->info("Supabase Auth: {$n} demo account(s) removed.");
        }
        $this->info('Done. The demo data is gone; deployments will not add it back.');

        return self::SUCCESS;
    }
}
