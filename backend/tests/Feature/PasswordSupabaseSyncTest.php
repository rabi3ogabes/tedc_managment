<?php

namespace Tests\Feature;

use App\Security\PasswordService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A password set in the platform reaches Supabase Auth when that is the identity store — at Supabase's full address. */
class PasswordSupabaseSyncTest extends TestCase
{
    public function test_a_new_password_is_sent_to_supabase_auth_at_its_full_address(): void
    {
        config(['tedc.auth.driver' => 'supabase', 'tedc.supabase.url' => 'https://project-ref.supabase.co', 'tedc.supabase.service_role_key' => 'sb_secret_test']);
        Http::fake(['https://project-ref.supabase.co/auth/v1/admin/users/*' => Http::response(['id' => 'auth-1'])]);
        $user = $this->makeUser();
        $user->forceFill(['auth_id' => 'auth-1'])->saveQuietly();

        // A relative address ("/admin/users/…") failed on the live site with "URI must include a scheme and host".
        app(PasswordService::class)->set($user, 'Shaheen#Portal-2026');

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->url() === 'https://project-ref.supabase.co/auth/v1/admin/users/auth-1' && $r['password'] === 'Shaheen#Portal-2026');
    }
}
