<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleUser;
use Tests\TestCase;

class ActiveRoleTest extends TestCase
{
    private function dualUser(): array
    {
        $user = $this->makeUser(Role::CENTER_ADMIN);
        $this->makeEmployee([], $user);
        $user->roles()->attach(Role::where('slug', Role::EMPLOYEE)->value('id'));
        $center = RoleUser::where('user_id', $user->id)->where('role_id', Role::where('slug', Role::CENTER_ADMIN)->value('id'))->first();
        $employee = RoleUser::where('user_id', $user->id)->where('role_id', Role::where('slug', Role::EMPLOYEE)->value('id'))->first();

        return [$user, $center, $employee];
    }

    public function test_me_lists_every_role_with_its_scope_and_marks_the_active_one(): void
    {
        [$user, $center, $employee] = $this->dualUser();

        $res = $this->asUser($user)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame(Role::CENTER_ADMIN, $res->json('data.active_role.slug'), 'the highest role is active until the user chooses');
        $row = collect($res->json('data.roles'))->firstWhere('slug', Role::EMPLOYEE);
        $this->assertSame($employee->id, $row['id']);
        $this->assertSame('ministry', $row['scope_type']);
        $this->assertNotEmpty($row['scope_label_ar']);
        $this->assertNotEmpty($row['landing_route']);
        $this->assertTrue(collect($res->json('data.roles'))->firstWhere('slug', Role::CENTER_ADMIN)['active']);
    }

    public function test_switching_role_changes_what_the_user_may_do_and_is_remembered(): void
    {
        [$user, $center, $employee] = $this->dualUser();

        $this->asUser($user)->getJson('/api/v1/admin/programs')->assertOk();

        $res = $this->asUser($user)->postJson('/api/v1/auth/active-role', ['role_user_id' => $employee->id])->assertOk();
        $this->assertSame(['search.global'], $res->json('data.permissions'));
        $this->assertSame(Role::EMPLOYEE, $res->json('data.active_role.slug'));

        $this->asUser($user)->getJson('/api/v1/admin/programs')->assertForbidden();
        $this->assertSame($employee->id, $user->fresh()->active_role_user_id);

        // The header of one request wins over the remembered choice, so two browser tabs can work as two roles.
        $this->asUser($user)->withHeader('X-Active-Role', $center->id)->getJson('/api/v1/admin/programs')->assertOk();
    }

    public function test_a_role_the_user_does_not_hold_or_that_expired_is_refused(): void
    {
        [$user, $center, $employee] = $this->dualUser();
        $stranger = $this->makeUser(Role::SUPER_ADMIN);
        $strangerRole = RoleUser::where('user_id', $stranger->id)->first();

        $this->asUser($user)->postJson('/api/v1/auth/active-role', ['role_user_id' => $strangerRole->id])->assertForbidden();
        $this->asUser($user)->withHeader('X-Active-Role', $strangerRole->id)->getJson('/api/v1/admin/programs')->assertForbidden();

        $center->update(['expires_at' => now()->subDay()]);
        $this->asUser($user)->postJson('/api/v1/auth/active-role', ['role_user_id' => $center->id])->assertForbidden();
        $this->asUser($user)->getJson('/api/v1/admin/programs')->assertForbidden('an expired role grants nothing, the user falls back to the employee role');
    }

    public function test_existing_users_keep_working_after_the_scope_migration(): void
    {
        // A school administrator created the old way (no scope given) is still limited to the school of their employee record.
        $school = $this->makeSchool();
        $user = $this->makeUser(Role::SCHOOL_ADMIN);
        $this->makeEmployee(['school_id' => $school->id], $user);
        $other = $this->makeEmployee();

        $ids = collect($this->asUser($user)->getJson('/api/v1/admin/employees')->assertOk()->json('data'))->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertNotContains($other->id, $ids);
    }
}
