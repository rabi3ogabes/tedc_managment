<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Support\RoleCharter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every deploy runs RolePermissionSeeder::additive() on the live database. It brings what is new in the release (a right, a
 * role) and must leave alone what an administrator decided in Settings → Roles & permissions: a right taken from a role stays
 * taken, deploy after deploy.
 */
class RolePermissionAdditiveTest extends TestCase
{
    private function holds(string $role, string $right): bool
    {
        return DB::table('permission_role')
            ->where('role_id', Role::where('slug', $role)->value('id'))
            ->where('permission_id', Permission::where('slug', $right)->value('id'))
            ->exists();
    }

    private function revoke(string $role, string $right): void
    {
        Role::where('slug', $role)->firstOrFail()->permissions()->detach(Permission::where('slug', $right)->value('id'));
    }

    public function test_a_right_an_administrator_took_from_a_role_is_not_given_back(): void
    {
        $this->assertTrue($this->holds(Role::TRAINING_HEAD, 'saber.import'), 'the seeder gives the head of training this right');
        $this->revoke(Role::TRAINING_HEAD, 'saber.import');

        (new RolePermissionSeeder)->additive();

        $this->assertFalse($this->holds(Role::TRAINING_HEAD, 'saber.import'), 'the right came back');
    }

    public function test_a_deploy_leaves_the_rights_of_the_roles_as_the_administrator_set_them(): void
    {
        $this->revoke(Role::TRAINING_HEAD, 'saber.import');
        $this->revoke(Role::CENTER_ADMIN, 'finance.reports');   // a role that holds everything («*») is no exception
        $this->revoke(Role::LOGISTICS_OFFICER, 'rooms.book');
        Role::where('slug', Role::EMPLOYEE)->firstOrFail()->permissions()->attach(Permission::where('slug', 'kits.view')->value('id'));
        $before = DB::table('permission_role')->count();

        $this->artisan('tedc:deploy', ['--no-cache' => true])->assertSuccessful();
        $this->artisan('tedc:deploy', ['--no-cache' => true])->assertSuccessful();

        $this->assertFalse($this->holds(Role::TRAINING_HEAD, 'saber.import'));
        $this->assertFalse($this->holds(Role::CENTER_ADMIN, 'finance.reports'));
        $this->assertFalse($this->holds(Role::LOGISTICS_OFFICER, 'rooms.book'));
        $this->assertTrue($this->holds(Role::EMPLOYEE, 'kits.view'), 'a right the administrator added stays');
        $this->assertSame($before, DB::table('permission_role')->count(), 'a deploy with nothing new changes no role');
    }

    public function test_a_right_new_in_the_release_reaches_the_roles_that_are_meant_to_hold_it_once(): void
    {
        // A database from before the right existed.
        Permission::where('slug', 'saber.import')->delete();
        $this->assertFalse($this->holds(Role::TRAINING_HEAD, 'saber.import'));

        (new RolePermissionSeeder)->additive();

        $this->assertSame('saber', Permission::where('slug', 'saber.import')->value('group'));
        $this->assertTrue($this->holds(Role::TRAINING_HEAD, 'saber.import'));
        $this->assertTrue($this->holds(Role::SUPER_ADMIN, 'saber.import'));
        $this->assertTrue($this->holds(Role::CENTER_ADMIN, 'saber.import'));
        $this->assertFalse($this->holds(Role::COORDINATOR, 'saber.import'), 'only the roles the seeder names');

        // From then on it is the administrator's to give and take.
        $this->revoke(Role::TRAINING_HEAD, 'saber.import');
        (new RolePermissionSeeder)->additive();
        $this->assertFalse($this->holds(Role::TRAINING_HEAD, 'saber.import'));
    }

    public function test_a_right_that_was_never_on_the_old_fixed_list_also_reaches_the_roles_when_it_is_new(): void
    {
        Permission::where('slug', 'surveys.export')->delete();

        (new RolePermissionSeeder)->additive();

        $this->assertTrue($this->holds(Role::PLANNING_HEAD, 'surveys.export'));
        $this->assertFalse($this->holds(Role::TRAINER, 'surveys.export'));
    }

    public function test_a_right_a_migration_of_the_same_deploy_created_still_reaches_the_other_roles(): void
    {
        // A migration (the role charter) creates the right first and gives it to the roles of the charter only.
        Permission::where('slug', 'live_training.view')->delete();
        $known = Permission::pluck('slug')->all();
        $this->assertSame([Role::COORDINATOR, Role::SUPERVISOR], array_keys(RoleCharter::grantMissing()));
        $this->assertFalse($this->holds(Role::TRAINING_HEAD, 'live_training.view'));

        (new RolePermissionSeeder)->additive($known);

        $this->assertTrue($this->holds(Role::TRAINING_HEAD, 'live_training.view'));
        $this->assertTrue($this->holds(Role::EXECUTIVE, 'live_training.view'));
        $this->assertTrue($this->holds(Role::COORDINATOR, 'live_training.view'));
        $this->assertFalse($this->holds(Role::TRAINER, 'live_training.view'));

        // The next deploy finds the right in the database: it is no longer new.
        $this->revoke(Role::EXECUTIVE, 'live_training.view');
        (new RolePermissionSeeder)->additive(Permission::pluck('slug')->all());
        $this->assertFalse($this->holds(Role::EXECUTIVE, 'live_training.view'));
    }

    public function test_a_role_new_in_the_release_gets_its_whole_set_and_the_others_are_not_touched(): void
    {
        Role::where('slug', Role::LOGISTICS_OFFICER)->delete();
        $this->revoke(Role::FINANCE_OFFICER, 'orders.view');

        (new RolePermissionSeeder)->additive();

        $role = Role::where('slug', Role::LOGISTICS_OFFICER)->firstOrFail();
        $this->assertTrue($role->is_system);
        $this->assertEqualsCanonicalizing(RolePermissionSeeder::ROLES[Role::LOGISTICS_OFFICER][3], $role->permissions()->pluck('slug')->all());
        $this->assertFalse($this->holds(Role::FINANCE_OFFICER, 'orders.view'));
    }
}
