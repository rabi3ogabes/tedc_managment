<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CAR-05: a profile change request that touches what HR masters is sent to HR; where it stands (sent, accepted, refused) is kept on the request. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_change_requests', function (Blueprint $table) {
            $table->string('hr_system', 10)->nullable();                    // hr | mawared: the system it was sent to
            $table->string('hr_status', 10)->nullable();                    // queued | sent | accepted | rejected | failed
            $table->string('hr_reference', 120)->nullable();                // HR's own number for it
            $table->unsignedSmallInteger('hr_attempts')->default(0);
            $table->timestamp('hr_next_attempt_at')->nullable();
            $table->timestamp('hr_sent_at')->nullable();
            $table->timestamp('hr_resolved_at')->nullable();
            $table->string('hr_reason', 500)->nullable();                   // why HR refused
            $table->string('hr_last_error', 300)->nullable();               // why the last attempt to send failed
            $table->index(['hr_status', 'hr_next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('profile_change_requests', function (Blueprint $table) {
            $table->dropIndex(['hr_status', 'hr_next_attempt_at']);
            $table->dropColumn(['hr_system', 'hr_status', 'hr_reference', 'hr_attempts', 'hr_next_attempt_at', 'hr_sent_at', 'hr_resolved_at', 'hr_reason', 'hr_last_error']);
        });
    }
};
