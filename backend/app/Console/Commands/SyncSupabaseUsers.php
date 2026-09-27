<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('tedc:supabase-sync-users {--password= : Initial password for new Supabase Auth users (otherwise a password-reset invite is sent)} {--email=* : Only sync these e-mails}')]
#[Description('Create / link platform users in Supabase Auth and store their auth_id')]
class SyncSupabaseUsers extends Command
{
    public function handle(): int
    {
        $url = rtrim((string) config('tedc.supabase.url'), '/');
        $key = (string) config('tedc.supabase.service_role_key');

        if (! $url || ! $key) {
            $this->error('Set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY in .env first.');

            return self::FAILURE;
        }

        $admin = Http::withHeaders(['apikey' => $key, 'Authorization' => "Bearer {$key}"])->baseUrl("{$url}/auth/v1")->timeout(20);
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

        $created = $linked = $failed = 0;
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
            }

            $user->forceFill(['auth_id' => $authId])->save();
        }

        $this->info("Supabase Auth: {$created} created, {$linked} linked, {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
