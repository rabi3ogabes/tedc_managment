<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells whether this environment's APP_KEY is the key the stored data was written with, by decrypting a sample of the
 * encrypted national IDs and comparing each blind-index fingerprint (both depend on APP_KEY). Prints counts only, never a value:
 * `php artisan tedc:app-key-check --env=live` before moving a database to a new server.
 */
#[Signature('tedc:app-key-check {--sample=50 : How many national IDs to test}')]
#[Description('Check that APP_KEY can decrypt the stored national IDs and matches their blind index (counts only)')]
class AppKeyCheck extends Command
{
    public function handle(): int
    {
        $key = (string) config('app.key');
        if ($key === '') {
            $this->error('This environment has no APP_KEY at all (the .env file the command read has no APP_KEY line).');

            return self::FAILURE;
        }
        $this->line('APP_KEY present: '.(str_starts_with($key, 'base64:') ? 'base64 form' : 'other form').', '.strlen($key).' characters.');

        $rows = DB::table('employees')->whereNotNull('national_id')->orderBy('id')->limit(max(1, (int) $this->option('sample')))->get(['national_id', 'national_id_hash']);
        if ($rows->isEmpty()) {
            $this->warn('No employee has a national ID in this database: nothing to test.');

            return self::SUCCESS;
        }

        $decrypted = 0;
        $withHash = 0;
        $fingerprint = 0;
        foreach ($rows as $row) {
            try {
                $plain = Crypt::decryptString((string) $row->national_id);
            } catch (Throwable) {
                continue;
            }
            $decrypted++;
            if ($row->national_id_hash !== null && $row->national_id_hash !== '') {
                $withHash++;
                if (hash_equals((string) $row->national_id_hash, Employee::nationalIdHash($plain))) {
                    $fingerprint++;
                }
            }
        }

        $n = $rows->count();
        $this->line("Tested {$n} national IDs: decrypted {$decrypted}; of {$withHash} with a fingerprint, {$fingerprint} match.");
        if ($decrypted === $n && $fingerprint === $withHash) {
            $this->info('This APP_KEY is the key of the stored data.');

            return self::SUCCESS;
        }
        $this->error('This APP_KEY is NOT the key of the stored data.');

        return self::FAILURE;
    }
}
