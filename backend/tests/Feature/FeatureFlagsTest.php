<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Services\FeatureSettings;
use App\Support\Features;
use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    private function production(): void
    {
        $this->app['env'] = 'production';
        app(FeatureSettings::class)->flush();
    }

    public function test_outside_production_the_working_tools_are_on_and_unfinished_features_are_off(): void
    {
        $this->assertTrue(Features::enabled('impersonation'));
        $this->assertTrue(Features::enabled('test_accounts'));
        $this->assertTrue(Features::enabled('ai'));
        $this->assertFalse(Features::enabled('payments'));
        $this->assertFalse(Features::enabled('plc'));
        $this->assertFalse(Features::enabled('a_flag_that_does_not_exist'));
    }

    public function test_in_production_the_four_unsafe_tools_start_off(): void
    {
        $this->production();

        foreach (['impersonation', 'test_accounts', 'demo_scenarios', 'self_heal'] as $flag) {
            $this->assertFalse(Features::enabled($flag), $flag);
        }
        $this->assertTrue(Features::enabled('ai'), 'features that are part of the product stay on');
    }

    public function test_the_unsafe_tools_cannot_be_used_in_production_until_an_administrator_turns_them_on_with_a_reason(): void
    {
        $this->production();
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $target = $this->makeEmployee()->user;

        $this->asUser($admin)->postJson("/api/v1/admin/users/{$target->id}/impersonate")->assertForbidden()->assertJsonPath('code', 'feature_disabled');
        $this->asUser($admin)->getJson('/api/v1/admin/test-accounts')->assertForbidden()->assertJsonPath('code', 'feature_disabled');

        // A reason is mandatory for these tools in production.
        $this->asUser($admin)->putJson('/api/v1/admin/features/impersonation', ['enabled' => true])->assertUnprocessable();
        $this->asUser($admin)->putJson('/api/v1/admin/features/impersonation', ['enabled' => true, 'reason' => 'Support case 4411: reproduce a trainee problem'])->assertOk()->assertJsonPath('data.enabled', true);

        $this->asUser($admin)->postJson("/api/v1/admin/users/{$target->id}/impersonate")->assertOk();
        $this->assertTrue(Features::enabled('impersonation'));

        $log = AuditLog::where('action', 'feature_toggled')->latest('created_at')->first();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('impersonation', $log->new_values['key']);
        $this->assertTrue($log->new_values['enabled']);
        $this->assertStringContainsString('Support case 4411', $log->new_values['reason']);
        $this->assertFalse($log->old_values['enabled']);
    }

    public function test_only_users_who_manage_settings_and_users_may_change_the_unsafe_tools_in_production(): void
    {
        $this->production();
        $trainee = $this->makeEmployee()->user;

        $this->asUser($trainee)->getJson('/api/v1/admin/features')->assertForbidden();
        $this->asUser($trainee)->putJson('/api/v1/admin/features/impersonation', ['enabled' => true, 'reason' => 'nothing to see here'])->assertForbidden();
    }

    public function test_every_signed_in_user_can_read_which_features_are_on_but_not_why(): void
    {
        $this->production();
        $user = $this->makeEmployee()->user;

        $res = $this->asUser($user)->getJson('/api/v1/features')->assertOk();
        $this->assertFalse($res->json('data.flags.impersonation'));
        $this->assertTrue($res->json('data.flags.ai'));
        $this->assertSame([], $res->json('data.unsafe_active'));
        $this->assertSame('production', $res->json('data.environment'));
        $this->assertArrayNotHasKey('reason', $res->json('data'));
    }

    public function test_the_banner_lists_unsafe_tools_that_are_switched_on_in_production(): void
    {
        $this->production();
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/features/test_accounts', ['enabled' => true, 'reason' => 'Presentation to the ministry on Sunday'])->assertOk();

        $this->assertSame(['test_accounts'], $this->asUser($admin)->getJson('/api/v1/features')->json('data.unsafe_active'));
    }

    public function test_turning_a_flag_off_again_is_audited_and_restores_the_default(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/features/payments', ['enabled' => true])->assertOk();
        $this->assertTrue(Features::enabled('payments'));

        $this->asUser($admin)->putJson('/api/v1/admin/features/payments', ['enabled' => false])->assertOk();
        $this->assertFalse(Features::enabled('payments'));
        $this->assertSame(2, AuditLog::where('action', 'feature_toggled')->count());

        $row = collect($this->asUser($admin)->getJson('/api/v1/admin/features')->assertOk()->json('data'))->firstWhere('key', 'payments');
        $this->assertSame('payments', $row['key']);
        $this->assertNotEmpty($row['history']);
        $this->asUser($admin)->putJson('/api/v1/admin/features/not_a_flag', ['enabled' => true])->assertNotFound();
    }
}
