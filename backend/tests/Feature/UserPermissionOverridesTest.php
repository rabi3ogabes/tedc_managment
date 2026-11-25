<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Tests\TestCase;

/** Rights of one person: added on top of a role they hold, or taken out of it, without touching the role. */
class UserPermissionOverridesTest extends TestCase
{
    private function grantOf(User $user, string $slug): RoleUser
    {
        return RoleUser::where('user_id', $user->id)->where('role_id', Role::where('slug', $slug)->value('id'))->firstOrFail();
    }

    private function set(User $admin, User $user, RoleUser $grant, array $granted, array $revoked)
    {
        return $this->asUser($admin)->putJson("/api/v1/admin/users/{$user->id}/roles/{$grant->id}/permissions", ['granted' => $granted, 'revoked' => $revoked]);
    }

    private function rightsOf(User $user, ?RoleUser $as = null): array
    {
        $request = $this->asUser($user);
        if ($as) {
            $request = $request->withHeader('X-Active-Role', $as->id);
        }

        return $request->getJson('/api/v1/auth/me')->assertOk()->json('data.permissions');
    }

    public function test_a_right_added_for_one_person_is_theirs_at_once_and_nobody_else_s(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $person = $this->makeUser(Role::SUPERVISOR);
        $colleague = $this->makeUser(Role::SUPERVISOR);
        $grant = $this->grantOf($person, Role::SUPERVISOR);
        $this->assertNotContains('certificates.view', $this->rightsOf($person));
        $this->asUser($person)->getJson('/api/v1/admin/certificates')->assertForbidden();

        $this->set($admin, $person, $grant, ['certificates.view'], [])->assertOk()
            ->assertJsonPath('data.granted_permissions', ['certificates.view'])->assertJsonPath('data.revoked_permissions', []);

        $this->assertContains('certificates.view', $this->rightsOf($person));
        $this->asUser($person)->getJson('/api/v1/admin/certificates')->assertOk();
        // The role is untouched: a colleague with the same role gains nothing.
        $this->assertNotContains('certificates.view', $this->rightsOf($colleague));
        $this->assertNotContains('certificates.view', Role::where('slug', Role::SUPERVISOR)->first()->permissions->pluck('slug')->all());
    }

    public function test_a_right_taken_from_one_person_is_gone_for_them_only(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $person = $this->makeUser(Role::SUPERVISOR);
        $colleague = $this->makeUser(Role::SUPERVISOR);
        $this->assertContains('employees.view', $this->rightsOf($person));

        $this->set($admin, $person, $this->grantOf($person, Role::SUPERVISOR), [], ['employees.view'])->assertOk()->assertJsonPath('data.revoked_permissions', ['employees.view']);

        $this->assertNotContains('employees.view', $this->rightsOf($person));
        $this->asUser($person)->getJson('/api/v1/admin/employees')->assertForbidden();
        $this->assertContains('employees.view', $this->rightsOf($colleague));
    }

    public function test_the_change_belongs_to_the_role_it_was_made_in(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $person = $this->makeEmployee()->user;
        $person->roles()->attach(Role::where('slug', Role::SUPERVISOR)->value('id'));
        $asSupervisor = $this->grantOf($person, Role::SUPERVISOR);
        $asEmployee = $this->grantOf($person, Role::EMPLOYEE);

        $this->set($admin, $person, $asSupervisor, ['certificates.view'], [])->assertOk();

        $this->assertContains('certificates.view', $this->rightsOf($person, $asSupervisor));
        $this->assertNotContains('certificates.view', $this->rightsOf($person, $asEmployee), 'working as «موظف» the person has the trainee\'s rights only');
    }

    public function test_what_is_sent_is_tidied_and_setting_nothing_puts_the_person_back_on_the_role(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $person = $this->makeUser(Role::SUPERVISOR);
        $grant = $this->grantOf($person, Role::SUPERVISOR);
        $roleRights = Role::where('slug', Role::SUPERVISOR)->first()->permissions->pluck('slug')->sort()->values()->all();

        // A right the role already gives is not "added"; a right the role does not give cannot be "taken".
        $this->set($admin, $person, $grant, ['employees.view', 'certificates.view', 'certificates.view'], ['kpi.view', 'employees.view'])->assertOk()
            ->assertJsonPath('data.granted_permissions', ['certificates.view'])->assertJsonPath('data.revoked_permissions', ['employees.view']);

        $this->set($admin, $person, $grant, [], [])->assertOk()->assertJsonPath('data.granted_permissions', [])->assertJsonPath('data.revoked_permissions', []);
        $this->assertNull($grant->fresh()->granted_permissions);
        $this->assertEqualsCanonicalizing($roleRights, $this->rightsOf($person));
    }

    public function test_only_rights_of_the_catalogue_can_be_given_and_the_system_administrator_is_left_alone(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $other = $this->makeUser(Role::SUPER_ADMIN);
        $person = $this->makeUser(Role::SUPERVISOR);
        $grant = $this->grantOf($person, Role::SUPERVISOR);

        $this->set($admin, $person, $grant, ['*'], [])->assertStatus(422);
        $this->set($admin, $person, $grant, ['not.a.right'], [])->assertStatus(422);
        // Kept out of the catalogue by design: the error log is the system administrator's alone.
        $this->set($admin, $person, $grant, ['logs.manage'], [])->assertStatus(422);
        $this->set($admin, $other, $this->grantOf($other, Role::SUPER_ADMIN), ['certificates.view'], [])->assertStatus(422);
        // A grant of somebody else is not found under this person.
        $this->set($admin, $person, $this->grantOf($other, Role::SUPER_ADMIN), ['certificates.view'], [])->assertNotFound();
        $this->assertNull($grant->fresh()->granted_permissions);
    }

    public function test_it_takes_the_right_to_manage_roles_and_every_change_is_written_down(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $person = $this->makeUser(Role::SUPERVISOR);
        $grant = $this->grantOf($person, Role::SUPERVISOR);

        // Somebody who cannot manage roles cannot change a person's rights, their own included.
        $this->set($person, $person, $grant, ['certificates.view'], [])->assertForbidden();

        $this->set($admin, $person, $grant, ['certificates.view'], ['employees.view'])->assertOk();
        $log = AuditLog::where('action', 'user_permissions_changed')->where('auditable_id', $person->id)->latest('created_at')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(['certificates.view'], $log->new_values['granted']);
        $this->assertSame(['employees.view'], $log->new_values['revoked']);
        $this->assertSame(Role::SUPERVISOR, $log->new_values['role']);
        $this->assertSame([], $log->old_values['granted']);
    }

    public function test_the_person_s_roles_and_what_they_see_carry_the_change_and_removing_the_role_removes_it(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $person = $this->makeEmployee()->user;
        $person->roles()->attach(Role::where('slug', Role::SUPERVISOR)->value('id'));
        $grant = $this->grantOf($person, Role::SUPERVISOR);
        $this->set($admin, $person, $grant, ['certificates.view'], ['employees.view'])->assertOk();

        $listed = collect($this->asUser($admin)->getJson("/api/v1/admin/users/{$person->id}/roles")->assertOk()->json('data'))->firstWhere('id', $grant->id);
        $this->assertSame(['certificates.view'], $listed['granted_permissions']);
        $this->assertSame(['employees.view'], $listed['revoked_permissions']);

        $seen = collect($this->asUser($admin)->getJson("/api/v1/admin/users/{$person->id}/access")->assertOk()->json('data.roles'))->firstWhere('id', $grant->id);
        $this->assertContains('certificates.view', $seen['permissions']);
        $this->assertNotContains('employees.view', $seen['permissions']);
        $this->assertSame(['certificates.view'], $seen['granted']);
        $this->assertSame(['employees.view'], $seen['revoked']);

        $this->asUser($admin)->deleteJson("/api/v1/admin/users/{$person->id}/roles/{$grant->id}")->assertOk();
        $this->assertNotContains('certificates.view', $this->rightsOf($person));
    }

    public function test_a_grant_that_has_expired_gives_nothing_added_or_not(): void
    {
        $person = $this->makeEmployee()->user;
        RoleUser::create(['user_id' => $person->id, 'role_id' => Role::where('slug', Role::SUPERVISOR)->value('id'), 'scope_type' => 'ministry', 'expires_at' => now()->subDay(), 'granted_permissions' => ['certificates.view']]);

        $this->assertNotContains('certificates.view', $this->rightsOf($person));
        $this->assertFalse($person->fresh()->hasPermission('certificates.view'));
    }
}
