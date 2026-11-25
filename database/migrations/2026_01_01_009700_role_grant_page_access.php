<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pages of one person (Settings → Users → the person → «الصفحات»), kept on the grant of the role like the person's
 * rights (009600):
 *
 *  - `hidden_pages`: pages of the menu the person is not shown although the role's rights would list them. Several pages
 *    can be opened by one right; this is how an administrator gives one of them and not the others.
 *  - `denied_actions`: per area of rights (`attendance`, `saber`…), which of adding, editing and deleting the person may
 *    not do there. It only ever takes away: nobody gains anything from it.
 *
 * Both start empty, so nothing changes for anybody until an administrator sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            if (! Schema::hasColumn('role_user', 'hidden_pages')) {
                $table->json('hidden_pages')->nullable();
            }
            if (! Schema::hasColumn('role_user', 'denied_actions')) {
                $table->json('denied_actions')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropColumn(['hidden_pages', 'denied_actions']);
        });
    }
};
