<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Supabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tedc:supabase-check {--email= : Also diagnose signing in with this account} {--password= : Password to try for --email}')]
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

        if ($this->option('email')) {
            $ok = $this->diagnoseLogin(strtolower(trim((string) $this->option('email'))), (string) $this->option('password')) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** Explains why an account can or cannot sign in (never prints the password). */
    private function diagnoseLogin(string $email, string $password): bool
    {
        $this->newLine();
        $this->line("<comment>Login diagnosis for {$email}</comment>");
        $this->line('Auth driver: '.config('tedc.auth.driver').(config('tedc.auth.driver') === 'supabase' ? '' : '  <error>(TEDC_AUTH_DRIVER is not "supabase": the web login does not use Supabase)</error>'));

        $local = User::where('email', $email)->first();
        $this->line('Platform user: '.($local ? "found (status: {$local->status}, auth_id: ".($local->auth_id ?? 'none').')' : '<error>not found in the platform database</error>'));

        $remote = null;
        try {
            for ($page = 1; $page <= 50 && ! $remote; $page++) {
                $users = Supabase::admin(20)->get(Supabase::url('/auth/v1/admin/users'), ['page' => $page, 'per_page' => 1000])->throw()->json('users') ?? [];
                $remote = collect($users)->first(fn ($u) => strtolower($u['email'] ?? '') === $email);
                if (count($users) < 1000) {
                    break;
                }
            }
            $this->line('Supabase Auth user: '.($remote
                ? "found (id: {$remote['id']}, confirmed: ".(! empty($remote['email_confirmed_at']) ? 'yes' : '<error>no</error>')
                    .(! empty($remote['banned_until']) ? ", <error>banned until {$remote['banned_until']}</error>" : '').')'
                : '<error>not found</error>'));
        } catch (Throwable $e) {
            $this->line('Supabase Auth user: <error>could not list users ('.$e->getMessage().')</error>');
        }

        if ($local && $remote && $local->auth_id && $local->auth_id !== $remote['id']) {
            $this->warn('The platform user is linked to a different Supabase id — run tedc:supabase-sync-users to relink.');
        }

        if ($password === '') {
            $this->line('Add --password=... to also test the sign-in itself.');

            return (bool) ($local && $remote);
        }

        $response = Supabase::public()->post(Supabase::url('/auth/v1/token?grant_type=password'), ['email' => $email, 'password' => $password]);
        if ($response->successful()) {
            $this->info('✔ Supabase accepted the e-mail and password.');

            return (bool) $local;
        }

        $code = $response->json('error_code') ?? $response->json('code');
        $this->line(sprintf('<error>✘ Supabase rejected the sign-in:</error> HTTP %d, %s — %s', $response->status(), $code ?? '?', $response->json('msg') ?? $response->json('error_description') ?? $response->body()));

        $this->warn(match (true) {
            ! $remote => 'Fix: php artisan tedc:supabase-sync-users --password="..." --email='.$email,
            $code === 'email_not_confirmed' => 'Fix: php artisan tedc:supabase-sync-users --password="..." --reset-password --email='.$email.' (also confirms the e-mail)',
            $code === 'email_provider_disabled' => 'Fix: enable Authentication → Sign In / Providers → Email in the Supabase dashboard.',
            $code === 'over_request_rate_limit' || $response->status() === 429 => 'Too many attempts — wait a few minutes and try again.',
            default => 'Fix: php artisan tedc:supabase-sync-users --password="..." --reset-password --email='.$email,
        });

        return false;
    }
}
