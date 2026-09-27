<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Serverless operations endpoints (Vercel Cron and one-time setup). */
class SystemEndpointsTest extends TestCase
{
    public function test_endpoints_do_not_exist_without_a_cron_secret(): void
    {
        config(['tedc.cron_secret' => null]);

        $this->getJson('/api/v1/system/cron')->assertNotFound();
        $this->postJson('/api/v1/system/setup')->assertNotFound();
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        config(['tedc.cron_secret' => 'right-secret']);

        $this->getJson('/api/v1/system/cron', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/system/setup', [], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    public function test_cron_runs_the_scheduled_commands(): void
    {
        config(['tedc.cron_secret' => 'right-secret']);

        $this->getJson('/api/v1/system/cron', ['Authorization' => 'Bearer right-secret'])
            ->assertOk()
            ->assertJsonPath('data', ['tedc:program-lifecycle' => 'ok', 'tedc:session-reminders' => 'ok', 'tedc:dispatch-surveys' => 'ok']);
    }

    public function test_setup_migrates_and_can_sync_supabase_users(): void
    {
        config([
            'tedc.cron_secret' => 'right-secret',
            'tedc.supabase.url' => 'https://demo-project.supabase.co',
            'tedc.supabase.service_role_key' => 'sb_secret_test',
        ]);
        Http::fake([
            'https://demo-project.supabase.co/auth/v1/admin/users?*' => Http::response(['users' => []]),
            'https://demo-project.supabase.co/auth/v1/admin/users' => Http::response(['id' => '66666666-6666-6666-6666-666666666666']),
        ]);
        $this->makeUser(Role::EMPLOYEE, ['email' => 'setup@tedc.qa']);

        $this->postJson('/api/v1/system/setup', ['sync_users_password' => 'Tedc@2026!'], ['Authorization' => 'Bearer right-secret'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/auth/v1/admin/users') && $r['password'] === 'Tedc@2026!');
    }
}
