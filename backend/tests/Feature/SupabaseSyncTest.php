<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseSyncTest extends TestCase
{
    public function test_sync_links_existing_and_creates_missing_auth_users(): void
    {
        config(['tedc.supabase.url' => 'https://demo.supabase.co', 'tedc.supabase.service_role_key' => 'service-key']);
        $existing = $this->makeUser(Role::EMPLOYEE, ['email' => 'known@tedc.qa']);
        $new = $this->makeUser(Role::EMPLOYEE, ['email' => 'new@tedc.qa']);

        Http::fake([
            'demo.supabase.co/auth/v1/admin/users?*' => Http::response(['users' => [['id' => '11111111-1111-1111-1111-111111111111', 'email' => 'known@tedc.qa']]]),
            'demo.supabase.co/auth/v1/admin/users' => Http::response(['id' => '22222222-2222-2222-2222-222222222222'], 200),
        ]);

        $this->artisan('tedc:supabase-sync-users', ['--password' => 'Tedc@2026!'])->assertSuccessful();

        $this->assertSame('11111111-1111-1111-1111-111111111111', $existing->fresh()->auth_id);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $new->fresh()->auth_id);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['email'] === 'new@tedc.qa' && $request['email_confirm'] === true && $request->hasHeader('apikey', 'service-key'));
    }
}
