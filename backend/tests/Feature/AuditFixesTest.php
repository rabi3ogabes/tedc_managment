<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Tests\TestCase;

/** Regression tests for problems found by walking every screen of the finished platform. */
class AuditFixesTest extends TestCase
{
    public function test_the_room_calendar_is_not_swallowed_by_the_single_room_route(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->getJson('/api/v1/admin/rooms/calendar?from=2026-10-04&to=2026-10-10')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_role_names_for_pickers_are_offered_without_permission_lists(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $r = $this->asUser($coordinator)->getJson('/api/v1/admin/role-options')->assertOk();
        $this->assertGreaterThan(5, count($r->json('data')));
        $this->assertArrayNotHasKey('permissions', $r->json());
        $this->assertArrayNotHasKey('permissions', $r->json('data.0'));
        $this->asUser($coordinator)->getJson('/api/v1/admin/roles')->assertForbidden();                 // the full list stays with user administrators
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/role-options')->assertForbidden();
    }

    public function test_the_error_log_stays_with_the_system_administrator_by_design(): void
    {
        $this->assertFalse(Permission::where('slug', 'logs.manage')->exists());   // no role can be granted it: only the system administrator passes the check
    }

    public function test_a_school_administrator_can_fill_the_program_filter_of_the_registration_screens(): void
    {
        $school = $this->makeUser(Role::SCHOOL_ADMIN);
        $this->makeProgram();
        $this->asUser($school)->getJson('/api/v1/admin/programs?per_page=100')->assertOk();
        $this->asUser($school)->getJson('/api/v1/admin/programs/'.$this->makeProgram()->id)->assertForbidden();   // details stay with those who manage programs
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/programs')->assertForbidden();
    }
}
