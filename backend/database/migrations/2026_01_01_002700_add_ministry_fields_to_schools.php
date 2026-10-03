<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The national school list (Ministry of Education "Where is my school"): its own number, where the record came from and when it was last refreshed.
        Schema::table('schools', function (Blueprint $table) {
            $table->string('moe_no', 24)->nullable()->index();
            $table->string('source', 16)->nullable()->index();   // moe_gov | moe_private | moe_special | manual
            $table->string('address')->nullable();
            $table->string('website')->nullable();
            $table->string('curriculum', 80)->nullable();
            $table->timestamp('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schools', fn (Blueprint $t) => $t->dropColumn(['moe_no', 'source', 'address', 'website', 'curriculum', 'synced_at']));
    }
};
