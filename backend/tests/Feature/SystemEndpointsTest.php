<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\HealthController;
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

    public function test_health_reports_database_state_without_secrets(): void
    {
        $this->getJson('/api/v1/public/health')->assertOk()
            ->assertJsonPath('data.database.connection', 'ok')
            ->assertJsonPath('data.database.tables', 'ok')
            ->assertJsonPath('data.app_key', 'set');
    }

    public function test_health_describes_database_errors_without_details(): void
    {
        $e = new \PDOException('SQLSTATE[08006] [7] connection to server at "db.internal" failed for user "postgres" password "secret-password"');

        $info = HealthController::describe(new \RuntimeException('wrapped', 0, $e), 'pgsql');

        $this->assertSame('08006', $info['sqlstate']);
        $this->assertStringContainsString('DB_URL', $info['hint']);
        $this->assertStringNotContainsString('secret', json_encode($info));
        $tenant = HealthController::describe(new \PDOException('SQLSTATE[08006] [7] connection to server at "aws-0-x.pooler.supabase.com" failed: FATAL:  Tenant or user not found'), 'pgsql');
        $this->assertSame('tenant_not_found', $tenant['reason']);
        $this->assertStringNotContainsString('aws-0-x', json_encode($tenant));
        $this->assertSame('42P05', HealthController::describe(new \PDOException('SQLSTATE[42P05]: Duplicate prepared statement'), 'pgsql')['sqlstate']);
    }
}
