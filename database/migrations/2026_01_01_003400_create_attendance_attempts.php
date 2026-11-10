<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every scan that did not record attendance (wrong program, already present, expired code, too early…) so the administrator can see what went wrong.
        Schema::create('attendance_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignUuid('program_session_id')->nullable()->constrained('program_sessions')->nullOnDelete();
            $table->string('outcome', 20);              // rejected | already_present
            $table->string('code', 40)->nullable();     // not_registered, already_checked_out, invalid_qr, …
            $table->text('message')->nullable();
            $table->string('device_info')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
            $table->index(['program_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_attempts');
    }
};
