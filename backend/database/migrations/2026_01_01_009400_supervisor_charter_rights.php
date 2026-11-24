<?php

use App\Support\RoleCharter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * «مشرف» takes the charter of «مشرف التدريب (قسم التدريب التربوي)» (RoleCharter::SHARED, decided by the centre on 2026-11-22):
 * on a database that already has its roles, the role is given, once, the rights that charter rests on and the right each of its
 * pages asks for. Nothing is taken away from any role, and a right the role already holds is left as it is. A new database gets
 * the rights from RolePermissionSeeder instead.
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
