<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Needs from the old school-request screen move into the needs-cycle requests; the link keeps the move repeatable and traceable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutional_requests', function (Blueprint $table) {
            $table->uuid('legacy_need_id')->nullable()->unique()->after('plan_item_id');   // the row of training_needs it came from
            $table->unsignedInteger('employees_count')->nullable()->after('employee_ids'); // a headcount when the people were not listed
        });
    }

    public function down(): void
    {
        Schema::table('institutional_requests', function (Blueprint $table) {
            $table->dropColumn(['legacy_need_id', 'employees_count']);
        });
    }
};
