<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Supabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:supabase-sync-users {--password= : Initial password for new Supabase Auth users (otherwise a password-reset invite is sent)} {--email=* : Only sync these e-mails} {--reset-password : Also set --password on users that already exist in Supabase Auth}')]
#[Description('Create / link platform users in Supabase Auth and store their auth_id')]
class SyncSupabaseUsers extends Command
{
    public function handle(): int
    {
        $url = rtrim((string) config('tedc.supabase.url'), '/');
        $key = (string) config('tedc.supabase.service_role_key');

        if (! $url || ! $key) {
            $this->error('Set SUPABASE_URL and SUPABASE_SECRET_KEY (or SUPABASE_SERVICE_ROLE_KEY) in .env first.');

            return self::FAILURE;
        }

        $admin = Supabase::admin(20)->baseUrl("{$url}/auth/v1");
        $password = $this->option('password');

        // Existing Supabase Auth users, keyed by e-mail.
        $existing = [];
        for ($page = 1; ; $page++) {
            $users = $admin->get('/admin/users', ['page' => $page, 'per_page' => 1000])->throw()->json('users') ?? [];
            foreach ($users as $u) {
                $existing[strtolower($u['email'] ?? '')] = $u['id'];
            }
            if (count($users) < 1000) {
                break;
            }
        }

        $query = User::query()->where('status', 'active');
        if ($emails = $this->option('email')) {
            $query->whereIn('email', array_map('strtolower', $emails));
        }

        if ($this->option('reset-password') && ! $password) {
            $this->error('--reset-password needs --password.');

            return self::FAILURE;
        }

        $created = $linked = $updated = $failed = 0;
        foreach ($query->cursor() as $user) {
            $authId = $existing[strtolower($user->email)] ?? null;

            if (! $authId) {
                $response = $admin->post('/admin/users', array_filter([
                    'email' => $user->email,
                    'password' => $password,
                    'email_confirm' => true,
                    'user_metadata' => ['full_name' => $user->name, 'full_name_ar' => $user->name_ar],
                ], fn ($v) => $v !== null));

                if ($response->failed()) {
                    $failed++;
                    $this->warn("✗ {$user->email}: ".$response->json('msg', $response->body()));

                    continue;
                }

                $authId = $response->json('id');
                if (! $password) {
                    $admin->post('/recover', ['email' => $user->email]);
                }
                $created++;
            } else {
                $linked++;

                if ($this->option('reset-password')) {
                    $response = $admin->put("/admin/users/{$authId}", ['password' => $password, 'email_confirm' => true]);
                    if ($response->failed()) {
                        $failed++;
                        $this->warn("✗ {$user->email}: ".$response->json('msg', $response->body()));
                    } else {
                        $updated++;
                    }
                }
            }

            $user->forceFill(['auth_id' => $authId])->save();
        }

        $this->info("Supabase Auth: {$created} created, {$linked} linked, {$updated} passwords reset, {$failed} failed.");
        if ($linked && ! $this->option('reset-password') && $password) {
            $this->line('Existing Supabase users keep their current password — add --reset-password to set it.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
