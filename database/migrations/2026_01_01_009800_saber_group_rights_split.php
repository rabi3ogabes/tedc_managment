<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * سابر+: one right, `saber.group.manage`, stood for adding a group, editing it, deleting it and editing the programme's
 * own fields, so an administrator could not give one of them without the others. It becomes four rights, each checked
 * by its own action:
 *
 *   saber.program.edit · saber.group.create · saber.group.edit · saber.group.delete
 *
 * On a database that already has its roles, everybody keeps exactly what they could do: each role that held the old
 * right is given the four, and so is each person it was added for (or taken from) by name. Then the old right goes.
 * A new database gets the four from RolePermissionSeeder and has nothing to translate.
 */
return new class extends Migration
{
    private const OLD = 'saber.group.manage';

    private const NEW = ['saber.program.edit', 'saber.group.create', 'saber.group.edit', 'saber.group.delete'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }
        $old = DB::table('permissions')->where('slug', self::OLD)->first();
        if (! $old) {
            return;
        }

        $ids = [];
        foreach (self::NEW as $slug) {
            [$group, $ar, $en] = RolePermissionSeeder::PERMISSIONS[$slug];
            $id = DB::table('permissions')->where('slug', $slug)->value('id');
            if (! $id) {
                $id = (string) Str::uuid7();
                DB::table('permissions')->insert(['id' => $id, 'slug' => $slug, 'group' => $group, 'name_ar' => $ar, 'name_en' => $en] + $this->stamps('permissions'));
            }
            $ids[] = $id;
        }

        // Every role that held the old right, the custom ones included.
        foreach (DB::table('permission_role')->where('permission_id', $old->id)->pluck('role_id') as $roleId) {
            foreach ($ids as $id) {
                if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $id)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $id]);
                }
            }
        }

        // Every person the old right was added for, or taken from, in one of their roles.
        if (Schema::hasColumn('role_user', 'granted_permissions')) {
            foreach (DB::table('role_user')->whereNotNull('granted_permissions')->orWhereNotNull('revoked_permissions')->get(['id', 'granted_permissions', 'revoked_permissions']) as $grant) {
                $change = [];
                foreach (['granted_permissions', 'revoked_permissions'] as $column) {
                    $list = json_decode((string) $grant->{$column}, true);
                    if (is_array($list) && in_array(self::OLD, $list, true)) {
                        $list = array_values(array_unique(array_merge(array_diff($list, [self::OLD]), self::NEW)));
                        sort($list);
                        $change[$column] = json_encode($list);
                    }
                }
                if ($change !== []) {
                    DB::table('role_user')->where('id', $grant->id)->update($change);
                }
            }
        }

        DB::table('permission_role')->where('permission_id', $old->id)->delete();
        DB::table('permissions')->where('id', $old->id)->delete();
    }

    public function down(): void
    {
        // Data only: the four rights stay. The old right is not brought back.
    }

    /** @return array<string, string> the timestamps, when the table keeps them */
    private function stamps(string $table): array
    {
        $now = now()->toDateTimeString();

        return array_filter(['created_at' => Schema::hasColumn($table, 'created_at') ? $now : null, 'updated_at' => Schema::hasColumn($table, 'updated_at') ? $now : null]);
    }
};
