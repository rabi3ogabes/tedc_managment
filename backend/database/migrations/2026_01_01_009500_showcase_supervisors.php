<?php

use App\Services\Admin\ShowcaseAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * «برامجي» shows a supervisor the groups he supervises. The showcase accounts were built without a supervisor on their
 * groups, so the showcase supervisors would open an empty page: where the showcase accounts exist, their sample groups
 * get their supervisor. Only the showcase programmes (TEST-SHOWCASE-…) are touched; nothing else in the database is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('training_groups') || ! Schema::hasTable('users')) {
            return;
        }
        $showcase = app(ShowcaseAccounts::class);
        if ($showcase->exists()) {
            $showcase->assignSupervisors();
        }
    }

    public function down(): void
    {
        // The groups keep their supervisor: removing the showcase accounts removes the groups themselves.
    }
};
