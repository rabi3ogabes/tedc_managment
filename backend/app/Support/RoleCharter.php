<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The charter of the roles: for each role of the Ministry's roles document («الصلاحيات»), what the role may do (الصلاحيات) and
 * what it answers for (المسؤوليات), in the words of the document.
 *
 * The text lives in resources/roles/charter.json, generated from the document and never retyped (scripts/roles-charter). Each
 * heading of «الصلاحيات» also names the rights of the system the role must hold for the heading to be true, and the page where it
 * is done. A role of the system that the document does not describe has no charter.
 */
class RoleCharter
{
    /**
     * Roles of the system that the document does not name and that take the charter of a role it does, by decision of the centre:
     * «مشرف» takes «مشرف التدريب (قسم التدريب التربوي)» (decided 2026-11-22).
     *
     * Such a role reads the other role's text on its card and holds the rights that text rests on, with the rights the pages of
     * its headings ask for, so that what the card says is what the role can do (the centre confirmed this on 2026-11-22, knowing
     * the rights are not limited to the person's own school). It keeps every right it had; it does not become the other role.
     */
    public const SHARED = [Role::SUPERVISOR => Role::COORDINATOR];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $roles = null;

    /** @return array<string, array<string, mixed>> role slug => charter */
    public static function all(): array
    {
        if (self::$roles === null) {
            $data = json_decode((string) file_get_contents(resource_path('roles/charter.json')), true, 32, JSON_THROW_ON_ERROR);
            self::$roles = $data['roles'];
        }

        return self::$roles;
    }

    /**
     * The charter of one role as the dashboard shows it, or null when the document does not describe the role and the role reads
     * no other role's text (SHARED). `text_of` names the role of the document the text belongs to.
     */
    public static function for(string $slug): ?array
    {
        $source = self::SHARED[$slug] ?? $slug;
        $charter = self::all()[$source] ?? null;

        return $charter ? ['role' => $slug, 'text_of' => $source] + $charter : null;
    }

    /**
     * The rights a role must hold for its charter to be true. For a role that takes another role's charter (SHARED) these are the
     * other role's, with the right each heading's page asks for: the role of the document holds those by its own definition, the
     * role that takes its charter would otherwise not be able to open the pages the card links to.
     *
     * @return list<string>
     */
    public static function rights(string $slug): array
    {
        $sections = self::all()[self::SHARED[$slug] ?? $slug]['permissions'] ?? [];
        $rights = array_merge([], ...array_map(fn (array $s) => $s['rights'], $sections));
        if (isset(self::SHARED[$slug])) {
            $rights = array_merge($rights, array_values(array_filter(array_map(fn (array $s) => $s['needs'] ?? null, $sections))));
        }

        return array_values(array_unique($rights));
    }

    /**
     * Gives every role of the charter the rights it lacks. Nothing is taken away, so a right an administrator added to a role stays.
     * A right of the catalogue that the database does not have yet is created first; a role that does not exist yet (a database
     * before its first seeding) is skipped.
     *
     * @return array<string, list<string>> role slug => the rights that were added
     */
    public static function grantMissing(): array
    {
        $added = [];
        $permissions = Permission::pluck('id', 'slug');
        foreach ([...array_keys(self::all()), ...array_keys(self::SHARED)] as $slug) {
            $role = Role::where('slug', $slug)->first();
            if (! $role) {
                continue;
            }
            $held = DB::table('permission_role')->where('role_id', $role->id)->pluck('permission_id')->flip();
            foreach (self::rights($slug) as $right) {
                if (! isset($permissions[$right]) && isset(RolePermissionSeeder::PERMISSIONS[$right])) {
                    [$group, $ar, $en] = RolePermissionSeeder::PERMISSIONS[$right];
                    $permissions[$right] = Permission::firstOrCreate(['slug' => $right], ['group' => $group, 'name_ar' => $ar, 'name_en' => $en])->id;
                }
                $id = $permissions[$right] ?? null;
                if ($id && ! $held->has($id)) {
                    DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $role->id]);
                    $added[$slug][] = $right;
                }
            }
        }
        if ($added) {
            Log::info('Role charter: rights given to roles that lacked them', $added);
        }

        return $added;
    }

    /** For tests that swap the file. */
    public static function forget(): void
    {
        self::$roles = null;
    }
}
