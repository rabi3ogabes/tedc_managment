<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment lifecycle: nominations, registrations, waiting lists and attendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nominations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('nominated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nominator_type', 24);  // school_admin, training_center
            $table->text('justification')->nullable();
            $table->string('status', 16)->default('pending')->index(); // pending, accepted, declined, converted
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('nomination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 24);          // self, school_nomination, center_nomination, bulk_import
            $table->string('status', 16)->default('pending')->index(); // pending, approved, rejected, waitlisted, cancelled, completed
            $table->json('eligibility_snapshot')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('attendance_percent', 5, 2)->default(0);
            $table->boolean('tasks_completed')->default(false);
            $table->boolean('evaluation_completed')->default(false);
            $table->string('certificate_status', 16)->default('pending'); // pending, eligible, blocked, issued
            $table->decimal('impact_score', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'employee_id']);
        });

        Schema::create('waiting_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('status', 16)->default('waiting'); // waiting, promoted, expired
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'employee_id']);
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('method', 16)->default('qr');      // qr, manual
            $table->string('status', 16)->default('present'); // present, late, absent, excused
            $table->unsignedInteger('minutes_attended')->default(0);
            $table->string('device_info')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['program_session_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
        Schema::dropIfExists('waiting_lists');
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('nominations');
    }
};
