<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سابر+ phase 3 (docs/saber/03-rooms-and-meetings.md): a meeting-room booking keeps who asked for it and why, and the bookings of one
 * recurring request (daily, weekly, every two weeks) share a series id so they are shown, edited and cancelled together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_bookings', function (Blueprint $table) {
            $table->string('requested_by', 160)->nullable();
            $table->text('reason')->nullable();
            $table->uuid('series_id')->nullable()->index();
            $table->string('recurrence', 10)->nullable();   // daily | weekly | biweekly
        });
    }

    public function down(): void
    {
        Schema::table('room_bookings', fn (Blueprint $t) => $t->dropColumn(['requested_by', 'reason', 'series_id', 'recurrence']));
    }
};
