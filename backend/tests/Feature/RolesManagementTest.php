<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SchoolGroup;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RolesManagementTest extends TestCase
{
    public function test_the_rfp_roles_exist_with_arabic_and_english_names_and_the_right_scopes(): void
    {
        foreach ([Role::ACADEMIC_DEPUTY, Role::TRAINING_HEAD, Role::CENTER_LEADERSHIP, Role::PLANNING_HEAD, Role::PLANNING_SPECIALIST, Role::LOGISTICS_OFFICER] as $slug) {
            $role = Role::where('slug', $slug)->first();
            $this->assertNotNull($role, $slug);
            $this->assertNotEmpty($role->name_ar);
            $this->assertNotEmpty($role->name_en);
            $this->assertNotEmpty($role->landing_route);
        }
        $this->assertSame('Training Supervisor', Role::where('slug', Role::COORDINATOR)->value('name_en'));
        $this->assertSame(['school', 'school_group', 'department'], Role::where('slug', Role::ACADEMIC_DEPUTY)->value('scope_levels'));
        $this->assertTrue(Role::where('slug', Role::TRAINING_HEAD)->first()->permissions->pluck('slug')->contains('program_grants.manage'));
        $this->assertTrue(Role::where('slug', Role::PLANNING_HEAD)->first()->permissions->pluck('slug')->contains('plans.approve'));
    }

    public function test_an_administrator_grants_and_revokes_a_role_at_a_scope_and_it_is_audited(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $user = $this->makeUser();
        $school = $this->makeSchool();
        $deputy = Role::where('slug', Role::ACADEMIC_DEPUTY)->first();

        $res = $this->asUser($admin)->postJson("/api/v1/admin/users/{$user->id}/roles", ['role_id' => $deputy->id, 'scope_type' => 'school', 'scope_id' => $school->id, 'expires_at' => now()->addYear()->toDateString()])->assertCreated();
        $grantId = $res->json('data.id');
        $this->assertSame($school->id, RoleUser::find($grantId)->scope_id);
        $this->assertSame($admin->id, RoleUser::find($grantId)->granted_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role_granted', 'user_id' => $admin->id]);

        // The same grant twice is refused; a scope the role cannot have is refused; a Ministry scope needs no scope id.
        $this->asUser($admin)->postJson("/api/v1/admin/users/{$user->id}/roles", ['role_id' => $deputy->id, 'scope_type' => 'school', 'scope_id' => $school->id])->assertUnprocessable();
        $this->asUser($admin)->postJson("/api/v1/admin/users/{$user->id}/roles", ['role_id' => $deputy->id, 'scope_type' => 'ministry'])->assertUnprocessable();
        $this->asUser($admin)->postJson("/api/v1/admin/users/{$user->id}/roles", ['role_id' => $deputy->id, 'scope_type' => 'school'])->assertUnprocessable();

        $this->asUser($admin)->deleteJson("/api/v1/admin/users/{$user->id}/roles/{$grantId}")->assertOk();
        $this->assertNull(RoleUser::find($grantId));
        $this->assertDatabaseHas('audit_logs', ['action' => 'role_revoked']);

        $this->asUser($this->makeEmployee()->user)->postJson("/api/v1/admin/users/{$user->id}/roles", ['role_id' => $deputy->id, 'scope_type' => 'school', 'scope_id' => $school->id])->assertForbidden();
    }

    public function test_only_a_super_administrator_can_grant_the_super_administrator_role(): void
    {
        $centerAdmin = $this->makeUser(Role::CENTER_ADMIN);
        $user = $this->makeUser();

        $this->asUser($centerAdmin)->postJson("/api/v1/admin/users/{$user->id}/roles", ['role_id' => Role::where('slug', Role::SUPER_ADMIN)->value('id'), 'scope_type' => 'ministry'])->assertForbidden();
    }

    public function test_custom_roles_are_created_cloned_edited_and_protected_from_deletion_when_in_use(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $permissions = Permission::whereIn('slug', ['programs.view', 'reports.view'])->pluck('slug')->all();

        $created = $this->asUser($admin)->postJson('/api/v1/admin/roles', ['name_ar' => 'مراجع الجودة الميداني', 'name_en' => 'Field quality reviewer', 'permissions' => $permissions, 'scope_levels' => ['school_group', 'school']])->assertCreated();
        $role = Role::find($created->json('data.id'));
        $this->assertFalse($role->is_system);
        $this->assertEqualsCanonicalizing($permissions, $role->permissions->pluck('slug')->all());
        $this->assertNotEmpty($role->slug);

        $clone = $this->asUser($admin)->postJson('/api/v1/admin/roles', ['name_ar' => 'نسخة', 'name_en' => 'Copy of trainer', 'clone_from' => Role::where('slug', Role::TRAINER)->value('id')])->assertCreated();
        $this->assertSame(Role::where('slug', Role::TRAINER)->first()->permissions->pluck('slug')->sort()->values()->all(), Role::find($clone->json('data.id'))->permissions->pluck('slug')->sort()->values()->all());

        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$role->id}", ['name_en' => 'Field QA', 'scope_levels' => ['school']])->assertOk();
        $this->assertSame('Field QA', $role->fresh()->name_en);

        $this->asUser($admin)->deleteJson('/api/v1/admin/roles/'.Role::where('slug', Role::TRAINER)->value('id'))->assertUnprocessable();   // a system role

        $holder = $this->makeUser();
        $holder->roles()->attach($role->id, ['scope_type' => 'school', 'scope_id' => $this->makeSchool()->id]);
        $this->asUser($admin)->deleteJson("/api/v1/admin/roles/{$role->id}")->assertUnprocessable();   // has users

        $holder->roles()->detach($role->id);
        $this->asUser($admin)->deleteJson("/api/v1/admin/roles/{$role->id}")->assertOk();
        $this->assertNull(Role::find($role->id));
        $this->assertSame(4, AuditLog::whereIn('action', ['role_created', 'role_updated', 'role_deleted'])->count(), 'two creations, one edit and one deletion');

        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->postJson('/api/v1/admin/roles', ['name_ar' => 'س', 'name_en' => 'x'])->assertForbidden();   // roles.create is for the super administrator
    }

    public function test_school_groups_are_managed_and_imported_from_a_csv_with_a_validation_report(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$a, $b] = [$this->makeSchool(['code' => 'SCH-A']), $this->makeSchool(['code' => 'SCH-B'])];

        $g = $this->asUser($admin)->postJson('/api/v1/admin/school-groups', ['code' => 'NORTH', 'name_ar' => 'الشمال', 'name_en' => 'North', 'type' => 'directorate'])->assertCreated()->json('data');
        $this->asUser($admin)->putJson("/api/v1/admin/school-groups/{$g['id']}/schools", ['school_ids' => [$a->id]])->assertOk();
        $this->assertSame(1, $this->asUser($admin)->getJson('/api/v1/admin/school-groups')->json('data.0.schools_count'));

        $csv = "group_code,group_name_ar,group_name_en,type,school_code\nSOUTH,الجنوب,South,cluster,SCH-A\nSOUTH,الجنوب,South,cluster,SCH-B\nSOUTH,الجنوب,South,cluster,NOPE\n";
        $report = $this->asUser($admin)->post('/api/v1/admin/school-groups/import', ['file' => UploadedFile::fake()->createWithContent('groups.csv', $csv)], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame(2, $report['linked']);
        $this->assertSame(['NOPE'], array_column($report['unknown_schools'], 'school_code'));
        $this->assertSame(2, SchoolGroup::where('code', 'SOUTH')->first()->schools()->count());

        $this->asUser($admin)->deleteJson("/api/v1/admin/school-groups/{$g['id']}")->assertOk();
        $this->asUser($this->makeUser(Role::SCHOOL_ADMIN))->getJson('/api/v1/admin/school-groups')->assertForbidden();
    }
}
