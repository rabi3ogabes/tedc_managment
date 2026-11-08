<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\ProgramGrant;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The super administrator edits what each role can do from Settings → Roles & permissions, so every right the
 * system enforces has to be in the catalogue (otherwise it can only ever be given to the super administrator),
 * and a change made there has to take effect — SABER+ included. docs/roles.md describes the four showcase roles.
 */
class RolesCatalogueTest extends TestCase
{
    /** Every `permission:` slug on a route and every hasPermission() check in the code is a catalogue right. */
    public function test_every_enforced_permission_is_in_the_catalogue_the_super_administrator_edits(): void
    {
        $enforced = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    foreach (explode('|', substr($middleware, 11)) as $slug) {
                        $enforced[$slug] = $route->uri();
                    }
                }
            }
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match_all("/(?:hasPermission|need)\('([a-z_]+(?:\.[a-z_]+)+)'\)/", file_get_contents($file->getPathname()), $m)) {
                foreach ($m[1] as $slug) {
                    $enforced[$slug] ??= basename($file->getPathname());
                }
            }
        }

        // Programme-grant abilities (attendance.mark…) are given per programme from the programme page, not by a role.
        $known = [...array_keys(RolePermissionSeeder::PERMISSIONS), ...ProgramGrant::ABILITIES];
        $missing = array_diff_key($enforced, array_flip($known));
        $this->assertSame([], $missing, 'Enforced but not in RolePermissionSeeder::PERMISSIONS (the super administrator could never give them out): '.json_encode($missing));
        $this->assertGreaterThan(100, count($enforced));
    }

    /** Every right in the catalogue is a row, in a group the roles screen shows, with both names. */
    public function test_the_catalogue_is_seeded_with_names_in_both_languages(): void
    {
        $this->assertSame(count(RolePermissionSeeder::PERMISSIONS), Permission::count());
        foreach (RolePermissionSeeder::PERMISSIONS as $slug => [$group, $ar, $en]) {
            $this->assertNotSame('', trim($group.$ar.$en), $slug);
            $this->assertNotSame('', trim($ar), $slug);
            $this->assertNotSame('', trim($en), $slug);
        }
    }

    /** SABER+: giving a role a saber.* right opens that action, taking it away closes it, and the change is audited. */
    public function test_the_super_administrator_grants_and_revokes_saber_rights_and_they_take_effect(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $role = Role::find($this->asUser($admin)->postJson('/api/v1/admin/roles', ['name_ar' => 'منسق سابر', 'name_en' => 'SABER+ coordinator', 'permissions' => []])->assertCreated()->json('data.id'));
        $holder = $this->makeUser();
        $holder->roles()->attach($role->id, ['scope_type' => 'ministry', 'scope_id' => null]);

        // Nothing yet: SABER+ is closed.
        $this->asUser($holder)->getJson('/api/v1/admin/saber/overview')->assertForbidden();

        // Open it for viewing only.
        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$role->id}/permissions", ['permissions' => ['saber.view']])->assertOk();
        $this->asUser($holder)->getJson('/api/v1/admin/saber/overview')->assertOk();
        $this->asUser($holder)->postJson('/api/v1/admin/saber/programs', [])->assertForbidden();   // creating needs saber.program.create

        // Add the right to create; the same endpoint now answers with validation, not a refusal.
        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$role->id}/permissions", ['permissions' => ['saber.view', 'saber.program.create']])->assertOk();
        $this->assertNotSame(403, $this->asUser($holder)->postJson('/api/v1/admin/saber/programs', [])->status());

        // Take it all away again.
        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$role->id}/permissions", ['permissions' => []])->assertOk();
        $this->asUser($holder)->getJson('/api/v1/admin/saber/overview')->assertForbidden();

        $this->assertSame(3, AuditLog::where('action', 'permissions_changed')->count());
    }

    /** A system role (the supervisor, trainer, trainee…) can be edited too, and only the super administrator may. */
    public function test_a_system_role_is_editable_by_the_super_administrator_only(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $trainer = Role::where('slug', Role::TRAINER)->first();
        $before = $trainer->permissions->pluck('slug')->all();

        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$trainer->id}/permissions", ['permissions' => array_merge($before, ['saber.view'])])->assertOk();
        $this->assertTrue($trainer->fresh()->permissions->pluck('slug')->contains('saber.view'));

        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$trainer->id}/permissions", ['permissions' => $before])->assertOk();
        $this->assertFalse($trainer->fresh()->permissions->pluck('slug')->contains('saber.view'));

        // The super administrator's own rights are implicit and cannot be cut; a lesser role cannot edit anyone's.
        $super = Role::where('slug', Role::SUPER_ADMIN)->first();
        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$super->id}/permissions", ['permissions' => []])->assertUnprocessable();
        $this->asUser($this->makeUser(Role::TRAINER))->putJson("/api/v1/admin/roles/{$trainer->id}/permissions", ['permissions' => ['saber.view']])->assertForbidden();
    }

    /** The error log right was enforced but missing from the screen, so no one but the super administrator could be given it. */
    public function test_the_error_log_right_can_be_given_to_a_role(): void
    {
        $this->assertNotNull(Permission::where('slug', 'logs.manage')->first());
        $role = Role::where('slug', Role::CENTER_ADMIN)->first();
        $user = $this->makeUser(Role::CENTER_ADMIN);
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $keep = $role->permissions->pluck('slug')->reject(fn ($s) => $s === 'logs.manage')->values()->all();
        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$role->id}/permissions", ['permissions' => $keep])->assertOk();
        $this->asUser($user)->getJson('/api/v1/admin/error-logs')->assertForbidden();

        $this->asUser($admin)->putJson("/api/v1/admin/roles/{$role->id}/permissions", ['permissions' => [...$keep, 'logs.manage']])->assertOk();
        $this->asUser($user)->getJson('/api/v1/admin/error-logs')->assertOk();
    }
}
