<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Site-wide settings (e.g. the visual theme managed from the Brand Studio).
 * Also registers the `settings.manage` permission for existing installations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (Schema::hasTable('permissions') && DB::table('permissions')->where('slug', 'settings.manage')->doesntExist()) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $id, 'slug' => 'settings.manage', 'name_en' => 'Brand & appearance', 'name_ar' => 'الهوية البصرية والمظهر',
                'group' => 'settings', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $centerAdmin = DB::table('roles')->where('slug', 'center_admin')->value('id');
            if ($centerAdmin) {
                DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $centerAdmin]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        DB::table('permissions')->where('slug', 'settings.manage')->delete();
    }
};
