<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLC Phase 3: trainee nominations. A nomination is the explicit, auditable choice of a person for a workshop
 * before (and after) the workshop is approved; once the workshop is approved an approved nomination becomes a
 * registration on the programme engine (seats, attendance, certificates), and the row keeps the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plc_nominations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('status', 16)->default('nominated')->index(); // nominated, approved, waitlisted, rejected, declined, withdrawn, completed
            $table->string('source', 16)->default('centre');            // centre, school
            $table->foreignUuid('nominated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->string('note', 500)->nullable();
            $table->json('clashes')->nullable();                         // overlapping programmes found when nominated
            $table->foreignUuid('registration_id')->nullable()->constrained('registrations')->nullOnDelete();
            $table->timestamps();
            $table->unique(['program_id', 'employee_id']);
            $table->index(['program_id', 'school_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plc_nominations');
    }
};
