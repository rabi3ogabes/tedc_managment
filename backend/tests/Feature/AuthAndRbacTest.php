<?php

namespace Tests\Feature;

use App\Auth\JwtVerifier;
use App\Models\Role;
use Tests\TestCase;

class AuthAndRbacTest extends TestCase
{
    public function test_login_returns_tokens_and_profile(): void
    {
        $user = $this->makeUser(Role::COORDINATOR, ['email' => 'coord@tedc.qa']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'coord@tedc.qa', 'password' => 'Secret#12345'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in', 'user' => ['id', 'roles', 'permissions']]);

        $this->withHeader('Authorization', 'Bearer '.$response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_invalid_credentials_are_rejected_in_arabic_by_default(): void
    {
        $this->makeUser(Role::EMPLOYEE, ['email' => 'x@tedc.qa']);

        $this->postJson('/api/v1/auth/login', ['email' => 'x@tedc.qa', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'بيانات الدخول غير صحيحة.');
    }

    public function test_refresh_token_cannot_be_used_as_access_token(): void
    {
        $user = $this->makeUser();
        $refresh = app(JwtVerifier::class)->issue(['sub' => $user->id, 'typ' => 'refresh'], 3600);

        $this->withHeader('Authorization', 'Bearer '.$refresh)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $token = $this->tokenFor($this->makeUser());

        $this->withHeader('Authorization', 'Bearer '.$token.'x')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_suspended_user_cannot_authenticate(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor($user);
        $user->update(['status' => 'suspended']);

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_permissions_are_enforced_per_role(): void
    {
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->asUser($this->makeUser(Role::EXECUTIVE))->getJson('/api/v1/admin/analytics/executive')->assertOk();
        $this->asUser($this->makeUser(Role::EXECUTIVE))->postJson('/api/v1/admin/programs', [])->assertForbidden();
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->putJson('/api/v1/admin/roles/'.Role::where('slug', Role::TRAINER)->value('id').'/permissions', ['permissions' => []])->assertForbidden();
    }

    public function test_only_super_admin_can_grant_super_admin(): void
    {
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))
            ->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'new@tedc.qa', 'roles' => [Role::SUPER_ADMIN]])
            ->assertForbidden();
    }

    public function test_changes_are_audited(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram();

        $this->asUser($admin)->patchJson("/api/v1/admin/programs/{$program->id}/status", ['status' => 'archived'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $program->id, 'action' => 'updated', 'user_id' => $admin->id]);
    }
}
