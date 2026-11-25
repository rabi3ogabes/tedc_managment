<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * سابر+, one feature at a time: adding a group, editing it, deleting it and editing the programme each have their own
 * right, so an administrator gives a person exactly the ones they need (Settings → Users → «الصفحات» → «سابر+»).
 */
class SaberFeatureRightsTest extends TestCase
{
    private const SABER = ['saber.program.create', 'saber.program.edit', 'saber.program.cancel', 'saber.program.reactivate', 'saber.group.create', 'saber.group.edit', 'saber.group.delete',
        'saber.group.rename', 'saber.group.validate', 'saber.day.edit', 'saber.room.assign', 'saber.trainer.assign', 'saber.supervisor.assign', 'saber.plan.view', 'saber.import',
        'saber.survey_links.manage', 'saber.publish', 'saber.meeting.recurring'];

    private function grantOf(User $user): RoleUser
    {
        return RoleUser::where('user_id', $user->id)->where('role_id', Role::where('slug', Role::SUPERVISOR)->value('id'))->firstOrFail();
    }

    /** A supervisor who opens سابر+ and holds exactly these of its features, whatever the role gives today. */
    private function personWith(User $admin, array $features): User
    {
        $person = $this->makeUser(Role::SUPERVISOR);
        $grant = $this->grantOf($person);
        $this->asUser($admin)->putJson("/api/v1/admin/users/{$person->id}/roles/{$grant->id}/permissions", [
            'granted' => ['saber.view', ...$features], 'revoked' => array_values(array_diff(self::SABER, $features)),
        ])->assertOk();

        return $person;
    }

    private function program(): Program
    {
        return $this->makeProgram(['total_hours' => 15, 'delivery_mode' => 'in_person']);
    }

    public function test_somebody_given_the_page_to_look_only_reads_it_and_changes_nothing(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $viewer = $this->personWith($admin, []);
        $program = $this->program();
        $group = $program->groups()->first();

        $can = $this->asUser($viewer)->getJson('/api/v1/admin/saber/overview')->assertOk()->json('data.can');
        foreach (self::SABER as $feature) {
            $this->assertFalse($can[$feature], "{$feature} is not his");
        }
        $this->asUser($viewer)->getJson('/api/v1/admin/saber/programs')->assertOk();
        $this->asUser($viewer)->postJson('/api/v1/admin/saber/programs', ['title' => 'برنامج جديد'])->assertForbidden();
        $this->asUser($viewer)->patchJson("/api/v1/admin/saber/programs/{$program->id}", ['kit_ready' => true])->assertForbidden();
        $this->asUser($viewer)->postJson("/api/v1/admin/saber/programs/{$program->id}/groups")->assertForbidden();
        $this->asUser($viewer)->patchJson("/api/v1/admin/saber/programs/{$program->id}/groups/{$group->id}", ['title_ar' => 'اسم'])->assertForbidden();
        $this->asUser($viewer)->deleteJson("/api/v1/admin/saber/programs/{$program->id}/groups/{$group->id}")->assertForbidden();
        $this->asUser($viewer)->postJson("/api/v1/admin/saber/programs/{$program->id}/cancel", ['reason' => 'سبب'])->assertForbidden();
    }

    public function test_adding_editing_and_deleting_a_group_are_given_one_by_one(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/saber/settings', ['group_delete_enabled' => true])->assertOk();
        $program = $this->program();
        $first = $program->groups()->first();
        $groups = "/api/v1/admin/saber/programs/{$program->id}/groups";

        // Adds groups; cannot edit or delete one.
        $adder = $this->personWith($admin, ['saber.group.create']);
        $id = $this->asUser($adder)->postJson($groups)->assertCreated()->json('data.id');
        $this->asUser($adder)->patchJson("{$groups}/{$id}", ['sequence' => 7])->assertForbidden();
        $this->asUser($adder)->patchJson("{$groups}/{$id}", ['title_ar' => 'مجموعة الصباح'])->assertForbidden();
        $this->asUser($adder)->deleteJson("{$groups}/{$id}")->assertForbidden();

        // Edits a group (number and name); cannot add or delete one.
        $editor = $this->personWith($admin, ['saber.group.edit']);
        $this->asUser($editor)->patchJson("{$groups}/{$first->id}", ['sequence' => 9, 'title_ar' => 'مجموعة المساء'])->assertOk()->assertJsonPath('data.number', 9);
        $this->asUser($editor)->postJson($groups)->assertForbidden();
        $this->asUser($editor)->deleteJson("{$groups}/{$first->id}")->assertForbidden();

        // Names a group only: the name, not its number.
        $namer = $this->personWith($admin, ['saber.group.rename']);
        $this->asUser($namer)->patchJson("{$groups}/{$first->id}", ['title_ar' => 'مجموعة أولى'])->assertOk();
        $this->asUser($namer)->patchJson("{$groups}/{$first->id}", ['sequence' => 3])->assertForbidden();

        // Deletes a group; cannot add or edit one.
        $remover = $this->personWith($admin, ['saber.group.delete']);
        $this->asUser($remover)->postJson($groups)->assertForbidden();
        $this->asUser($remover)->patchJson("{$groups}/{$first->id}", ['sequence' => 4])->assertForbidden();
        // The programme's draft holds two groups now (the one added above): he deletes that one, and the last one stays.
        $this->asUser($remover)->deleteJson("{$groups}/{$id}")->assertOk();
        $this->asUser($remover)->deleteJson("{$groups}/{$first->id}")->assertUnprocessable()->assertJsonPath('code', 'last_group');
    }

    public function test_adding_a_programme_and_editing_its_details_are_separate(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $program = $this->program();

        $creator = $this->personWith($admin, ['saber.program.create']);
        $this->assertNotSame(403, $this->asUser($creator)->postJson('/api/v1/admin/saber/programs', [])->status(), 'creating is his: an empty form is a validation matter');
        $this->asUser($creator)->patchJson("/api/v1/admin/saber/programs/{$program->id}", ['kit_ready' => true])->assertForbidden();

        $editor = $this->personWith($admin, ['saber.program.edit']);
        $this->asUser($editor)->patchJson("/api/v1/admin/saber/programs/{$program->id}", ['kit_ready' => true])->assertOk()->assertJsonPath('data.kit_ready', true);
        $this->asUser($editor)->postJson('/api/v1/admin/saber/programs', ['title' => 'برنامج جديد'])->assertForbidden();
        $this->asUser($editor)->postJson("/api/v1/admin/saber/programs/{$program->id}/groups")->assertForbidden();
        $can = $this->asUser($editor)->getJson('/api/v1/admin/saber/overview')->json('data.can');
        $this->assertTrue($can['saber.program.edit']);
        $this->assertFalse($can['saber.group.create']);
    }

    public function test_the_old_combined_right_is_gone_from_the_catalogue_and_each_role_that_had_it_holds_the_four(): void
    {
        $this->assertFalse(Permission::where('slug', 'saber.group.manage')->exists());
        foreach ([Role::COORDINATOR, Role::TRAINING_HEAD] as $slug) {
            $held = Role::where('slug', $slug)->first()->permissions->pluck('slug');
            foreach (['saber.program.edit', 'saber.group.create', 'saber.group.edit', 'saber.group.delete'] as $right) {
                $this->assertTrue($held->contains($right), "{$slug} holds {$right}");
            }
        }
    }

    public function test_an_existing_database_is_translated_so_everybody_keeps_what_they_could_do(): void
    {
        // The state before the split: the old right, held by a custom role, added for one person and taken from another.
        $four = ['saber.program.edit', 'saber.group.create', 'saber.group.edit', 'saber.group.delete'];
        $supervisor = Role::where('slug', Role::SUPERVISOR)->first();
        DB::table('permission_role')->whereIn('permission_id', Permission::whereIn('slug', $four)->pluck('id'))->delete();
        Permission::whereIn('slug', $four)->delete();
        $old = Permission::create(['slug' => 'saber.group.manage', 'group' => 'saber', 'name_ar' => 'إدارة المجموعات', 'name_en' => 'Manage groups']);
        $custom = Role::create(['slug' => 'planner-'.Str::lower(Str::random(5)), 'name_ar' => 'مخطط', 'name_en' => 'Planner', 'level' => 30, 'is_system' => false]);
        $custom->permissions()->attach($old->id);
        $bystander = Role::where('slug', Role::EMPLOYEE)->first();
        $given = $this->makeUser(Role::SUPERVISOR);
        $taken = $this->makeUser(Role::SUPERVISOR);
        $this->grantOf($given)->update(['granted_permissions' => ['certificates.view', 'saber.group.manage']]);
        $this->grantOf($taken)->update(['revoked_permissions' => ['saber.group.manage']]);

        (require database_path('migrations/2026_01_01_009800_saber_group_rights_split.php'))->up();

        $this->assertFalse(Permission::where('slug', 'saber.group.manage')->exists());
        $this->assertEqualsCanonicalizing($four, $custom->fresh()->permissions->pluck('slug')->all());
        $this->assertEmpty(array_intersect($four, $bystander->fresh()->permissions->pluck('slug')->all()), 'a role that never had it gains nothing');
        $this->assertEmpty(array_intersect($four, $supervisor->fresh()->permissions->pluck('slug')->all()));
        $this->assertEqualsCanonicalizing(['certificates.view', ...$four], $this->grantOf($given)->fresh()->granted_permissions);
        $this->assertEqualsCanonicalizing($four, $this->grantOf($taken)->fresh()->revoked_permissions);
        // Running it again changes nothing.
        (require database_path('migrations/2026_01_01_009800_saber_group_rights_split.php'))->up();
        $this->assertCount(4, $custom->fresh()->permissions);
    }
}
