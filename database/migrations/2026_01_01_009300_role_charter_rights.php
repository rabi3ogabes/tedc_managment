<?php

use App\Support\RoleCharter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The roles document («الصلاحيات»): every role it describes is given, once, the rights it must hold for its charter to be true
 * (RoleCharter, docs/roles-charter.md). On a database where an administrator had removed one of these rights from a role, the
 * role gets it back; nothing is taken away from any role. A new database gets the rights from RolePermissionSeeder instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permission_role') && Schema::hasTable('roles')) {
            RoleCharter::grantMissing();
        }
    }

    public function down(): void
    {
        // Data only: rights given to a role stay. Remove one in Settings → Roles & permissions if needed.
    }
};
