<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PLC Phase 5: the channel a person attended through (hybrid sessions), corrections with their reason, and a lock per session. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->string('channel', 12)->nullable();              // in_person | remote
            $table->string('correction_reason', 500)->nullable();
            $table->foreignUuid('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
        });
        Schema::table('program_sessions', function (Blueprint $table) {
            $table->timestamp('attendance_locked_at')->nullable();
            $table->foreignUuid('attendance_locked_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('program_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_locked_by');
            $table->dropColumn('attendance_locked_at');
        });
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn(['channel', 'correction_reason', 'corrected_at']);
        });
    }
};
