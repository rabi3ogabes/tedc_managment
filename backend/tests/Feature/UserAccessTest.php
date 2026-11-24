<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleUser;
use Tests\TestCase;

/** «ما يراه»: the administrator reads what a person sees, role by role, before opening the account. */
class UserAccessTest extends TestCase
{
    public function test_it_lists_each_role_with_its_own_rights_and_the_position(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $person = $this->makeEmployee()->user;
        $person->roles()->attach(Role::where('slug', Role::SUPERVISOR)->value('id'));

        $res = $this->asUser($admin)->getJson("/api/v1/admin/users/{$person->id}/access")->assertOk()
            ->assertJsonPath('data.user.id', $person->id)
            ->assertJsonPath('data.position.has_trainee_profile', true)
            ->assertJsonPath('data.position.school.ar', 'مدرسة')
            ->assertJsonCount(2, 'data.roles');

        $roles = collect($res->json('data.roles'))->keyBy('slug');
        // The rights are those of each role, never the two added together: a person works in one role at a time.
        $this->assertEqualsCanonicalizing(Role::where('slug', Role::SUPERVISOR)->first()->permissions->pluck('slug')->all(), $roles[Role::SUPERVISOR]['permissions']);
        $this->assertEqualsCanonicalizing(Role::where('slug', Role::EMPLOYEE)->first()->permissions->pluck('slug')->all(), $roles[Role::EMPLOYEE]['permissions']);
        $this->assertSame('/portal', $roles[Role::EMPLOYEE]['landing_route']);
        $this->assertSame(1, collect($res->json('data.roles'))->where('starts_in', true)->count());
    }

    public function test_a_right_given_to_the_role_shows_at_once_and_an_expired_role_is_left_out(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $person = $this->makeEmployee()->user;
        $trainer = Role::where('slug', Role::TRAINER)->first();
        RoleUser::create(['user_id' => $person->id, 'role_id' => $trainer->id, 'scope_type' => 'ministry', 'expires_at' => now()->subDay()]);
        $employee = Role::where('slug', Role::EMPLOYEE)->first();
        $this->assertNotContains('saber.view', $employee->permissions->pluck('slug')->all());

        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$employee->id}/permissions", [
            'permissions' => [...$employee->permissions->pluck('slug')->all(), 'saber.view'],
        ])->assertOk();

        $roles = $this->asUser($admin)->getJson("/api/v1/admin/users/{$person->id}/access")->assertOk()->json('data.roles');
        $this->assertSame([Role::EMPLOYEE], array_column($roles, 'slug'));
        $this->assertContains('saber.view', $roles[0]['permissions']);

        // And the person's own session carries it on the very next request.
        $this->asUser($person)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertContains('saber.view', $this->asUser($person)->getJson('/api/v1/auth/me')->json('data.permissions'));
    }

    public function test_it_says_whether_the_account_can_be_opened_and_why_not(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $other = $this->makeUser(Role::SUPER_ADMIN);
        $center = $this->makeUser(Role::CENTER_ADMIN);
        $person = $this->makeEmployee()->user;
        $reason = fn ($as, $whom) => $this->asUser($as)->getJson("/api/v1/admin/users/{$whom->id}/access")->assertOk()->json('data.sign_in');

        $this->assertSame(['allowed' => true, 'reason' => null], $reason($admin, $person));
        $this->assertSame('self', $reason($admin, $admin)['reason']);
        $this->assertSame('admin', $reason($admin, $other)['reason']);
        $this->assertSame(['*'], $this->asUser($admin)->getJson("/api/v1/admin/users/{$other->id}/access")->json('data.roles.0.permissions'));
        if ($center->hasPermission('users.manage')) {
            $this->assertSame('not_system_admin', $reason($center, $person)['reason']);
        }

        $person->update(['status' => 'suspended']);
        $this->assertSame('inactive', $reason($admin, $person)['reason']);
        $person->update(['status' => 'active']);

        // The tool is switched off (its state on a live system until the administrator switches it on with a reason).
        $this->asUser($admin)->putJson('/api/v1/admin/features/impersonation', ['enabled' => false, 'reason' => 'test'])->assertOk();
        $this->assertSame(['allowed' => false, 'reason' => 'feature_off'], $reason($admin, $person));
    }

    public function test_only_those_who_manage_users_may_read_it(): void
    {
        $person = $this->makeEmployee()->user;
        $this->assertFalse(Permission::where('slug', 'users.manage')->whereHas('roles', fn ($q) => $q->where('slug', Role::EMPLOYEE))->exists());

        $this->asUser($person)->getJson("/api/v1/admin/users/{$person->id}/access")->assertForbidden();
    }
}
