<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rights of one person: a role a person holds can carry rights added for that person and rights taken from that person,
 * on top of the role's own (Settings → Users → the person → «الصلاحيات»). They live on the grant of the role, not on the
 * person, because a person works in one role at a time: the change belongs to that role of theirs, at its scope, and
 * goes away with the grant. Both start empty, so nothing changes for anybody until an administrator sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            if (! Schema::hasColumn('role_user', 'granted_permissions')) {
                $table->json('granted_permissions')->nullable();
            }
            if (! Schema::hasColumn('role_user', 'revoked_permissions')) {
                $table->json('revoked_permissions')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropColumn(['granted_permissions', 'revoked_permissions']);
        });
    }
};
