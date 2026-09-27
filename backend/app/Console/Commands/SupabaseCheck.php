<?php

namespace App\Console\Commands;

use App\Support\Supabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tedc:supabase-check')]
#[Description('Check that the backend can reach Supabase over HTTPS with the configured keys')]
class SupabaseCheck extends Command
{
    public function handle(): int
    {
        if (! config('tedc.supabase.url')) {
            $this->error('SUPABASE_URL is not set in backend/.env.');

            return self::FAILURE;
        }

        $bundle = (string) config('tedc.supabase.ca_bundle');
        if ($bundle !== '' && ! is_readable($bundle)) {
            $this->error("SUPABASE_CA_BUNDLE points to a file that does not exist or is not readable: {$bundle}");

            return self::FAILURE;
        }

        $this->line('URL:        '.Supabase::url());
        $this->line('CA bundle:  '.Supabase::caBundle());

        $checks = [
            'Auth (publishable key)' => fn () => Supabase::public()->get(Supabase::url('/auth/v1/settings')),
            'Signing keys (JWKS)' => fn () => Supabase::http()->get(Supabase::jwksUrl()),
            'Admin API (secret key)' => fn () => Supabase::admin(10)->get(Supabase::url('/auth/v1/admin/users'), ['per_page' => 1]),
        ];

        $ok = true;
        foreach ($checks as $name => $check) {
            try {
                $response = $check();
                $passed = $response->successful();
                $this->line(sprintf('%s %-24s HTTP %d', $passed ? '<info>✔</info>' : '<error>✘</error>', $name, $response->status()));
                $ok = $ok && $passed;
            } catch (Throwable $e) {
                $ok = false;
                $this->line(sprintf('<error>✘</error> %-24s %s', $name, $e->getMessage()));
                if (Supabase::isCertificateError($e)) {
                    $this->warn(Supabase::certificateHint());

                    return self::FAILURE;
                }
            }
        }

        $ok ? $this->info('Supabase connection OK.') : $this->error('Some checks failed — verify the keys in backend/.env.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
