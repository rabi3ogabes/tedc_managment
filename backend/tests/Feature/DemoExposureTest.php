<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Services\FeatureSettings;
use App\Support\DemoCredentials;
use App\Support\DemoGuard;
use Illuminate\Support\Facades\Hash;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A live system never runs in demo mode, the shared demo password never ships to browsers or phones, deployments never reset
 * passwords, and administrators can find and neutralise the accounts that may still use it.
 */
class DemoExposureTest extends TestCase
{
    private function production(): void
    {
        $this->app['env'] = 'production';
        app(FeatureSettings::class)->flush();
    }

    public function test_a_live_system_never_runs_demo_mode_even_with_the_old_opt_in_switch(): void
    {
        $this->production();
        config(['tedc.demo.allow_in_production' => true]);

        $this->assertFalse(DemoGuard::allowed());
        $this->getJson('/api/v1/public/mobile-config')->assertOk()
            ->assertJsonPath('data.demo_accounts', false)
            ->assertJsonPath('data.demo_password', null);
    }

    public function test_outside_production_the_sign_in_screens_get_the_demo_password_from_the_server(): void
    {
        $this->getJson('/api/v1/public/mobile-config')->assertOk()
            ->assertJsonPath('data.demo_accounts', true)
            ->assertJsonPath('data.demo_password', DemoCredentials::PASSWORD);
    }

    public function test_the_demo_password_is_not_written_into_the_web_or_app_code(): void
    {
        $found = [];
        foreach ([base_path('../web/src'), base_path('../mobile/lib')] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), DemoCredentials::PASSWORD)) {
                    $found[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $found, 'the shared demo password is shipped to clients in: '.implode(', ', $found));
    }

    public function test_deployments_never_reset_passwords(): void
    {
        $script = (string) file_get_contents(base_path('scripts/vercel-build.php'));

        $this->assertStringNotContainsString(DemoCredentials::PASSWORD, $script);
        $this->assertStringNotContainsString('--password', $script);
    }

    public function test_the_security_page_lists_the_accounts_that_may_still_use_the_demo_password(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'admin@tedc.qa', 'password' => DemoCredentials::PASSWORD]);
        $teacher = $this->makeUser(Role::EMPLOYEE, ['email' => 'teacher@tedc.qa', 'password' => DemoCredentials::PASSWORD]);
        $changed = $this->makeUser(Role::EMPLOYEE, ['email' => 'trainee1@tedc.qa', 'password' => 'Another#Pass123']);
        $shared = $this->makeUser(Role::EMPLOYEE, ['email' => 'someone@moe.gov.qa', 'password' => DemoCredentials::PASSWORD]);

        $rows = collect($this->asUser($admin)->getJson('/api/v1/admin/security/demo-accounts')->assertOk()->json('data.accounts'));

        $this->assertEqualsCanonicalizing([$admin->id, $teacher->id, $shared->id], $rows->pluck('id')->all());
        $this->assertTrue($rows->firstWhere('id', $admin->id)['is_you']);
        $this->assertNull($rows->firstWhere('id', $changed->id));
    }

    public function test_an_administrator_suspends_or_resets_the_listed_accounts_but_never_their_own(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'admin@tedc.qa', 'password' => DemoCredentials::PASSWORD]);
        $teacher = $this->makeUser(Role::EMPLOYEE, ['email' => 'teacher@tedc.qa', 'password' => DemoCredentials::PASSWORD]);
        $shared = $this->makeUser(Role::EMPLOYEE, ['email' => 'someone@moe.gov.qa', 'password' => DemoCredentials::PASSWORD]);

        $this->asUser($admin)->postJson('/api/v1/admin/security/demo-accounts/neutralise', ['mode' => 'suspend', 'user_ids' => [$teacher->id, $admin->id]])
            ->assertOk()->assertJsonPath('data.done', 1)->assertJsonPath('data.skipped_self', true);
        $this->assertSame('suspended', $teacher->fresh()->status);
        $this->assertSame('active', $admin->fresh()->status ?? 'active');

        $this->asUser($admin)->postJson('/api/v1/admin/security/demo-accounts/neutralise', ['mode' => 'reset', 'user_ids' => [$shared->id]])->assertOk()->assertJsonPath('data.done', 1);
        $this->assertFalse(Hash::check(DemoCredentials::PASSWORD, $shared->fresh()->password));
        $this->assertNotNull($shared->fresh()->password_changed_at);

        $this->assertSame(2, AuditLog::where('action', 'demo_accounts_neutralised')->count());
    }

    public function test_only_security_administrators_may_see_or_change_them(): void
    {
        $employee = $this->makeUser(Role::EMPLOYEE);

        $this->asUser($employee)->getJson('/api/v1/admin/security/demo-accounts')->assertForbidden();
        $this->asUser($employee)->postJson('/api/v1/admin/security/demo-accounts/neutralise', ['mode' => 'suspend', 'user_ids' => [$employee->id]])->assertForbidden();
    }
}
