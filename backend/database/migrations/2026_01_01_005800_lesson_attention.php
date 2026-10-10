<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The learner's own pauses of a lesson video, counted against the lesson's pause limit (`settings.lock_pause`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_progress', fn (Blueprint $table) => $table->unsignedSmallInteger('pause_count')->default(0));
    }

    public function down(): void
    {
        Schema::table('lesson_progress', fn (Blueprint $table) => $table->dropColumn('pause_count'));
    }
};
