<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location-verified attendance: where the participant was when they checked in, and how the check went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            // verified | no_venue | online | disabled | manual
            $table->string('location_status', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'accuracy_m', 'distance_m', 'location_status']);
        });
    }
};
