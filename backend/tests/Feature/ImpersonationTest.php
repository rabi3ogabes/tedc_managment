<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    public function test_the_system_admin_signs_in_as_a_user_and_it_is_audited(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $employee = $this->makeEmployee()->user;

        $res = $this->asUser($admin)->postJson("/api/v1/admin/users/{$employee->id}/impersonate")->assertOk()
            ->assertJsonPath('user.id', $employee->id)->assertJsonPath('impersonator.id', $admin->id)->assertJsonPath('expires_in', 3600);
        $token = $res->json('access_token');

        // The borrowed token acts as that user.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $employee->id);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'impersonation_started', 'auditable_id' => $employee->id]);

        $this->asUser($admin)->postJson("/api/v1/admin/users/{$employee->id}/impersonate/stop")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'impersonation_ended', 'auditable_id' => $employee->id]);
    }

    public function test_only_the_system_admin_can_and_not_into_admins_or_oneself(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $other = $this->makeUser(Role::SUPER_ADMIN);
        $center = $this->makeUser(Role::CENTER_ADMIN);
        $employee = $this->makeEmployee()->user;

        $this->asUser($center)->postJson("/api/v1/admin/users/{$employee->id}/impersonate")->assertForbidden();
        $this->asUser($admin)->postJson("/api/v1/admin/users/{$admin->id}/impersonate")->assertStatus(422)->assertJsonPath('code', 'impersonation_self');
        $this->asUser($admin)->postJson("/api/v1/admin/users/{$other->id}/impersonate")->assertStatus(422)->assertJsonPath('code', 'impersonation_admin');
        $employee->update(['status' => 'suspended']);
        $this->asUser($admin)->postJson("/api/v1/admin/users/{$employee->id}/impersonate")->assertStatus(422)->assertJsonPath('code', 'impersonation_inactive');
    }
}
