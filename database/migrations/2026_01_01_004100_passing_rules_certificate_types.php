<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 07 — weighted passing policies, two-stage task approval, pass exceptions and certificate types. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passing_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 8);                      // global | program | group
            $table->uuid('scope_id')->nullable();
            $table->string('mode', 16)->default('all_required');   // all_required | weighted
            $table->json('criteria');                        // [{key, required, min, weight}]
            $table->decimal('pass_threshold', 5, 2)->default(60);
            $table->json('assessment_ids')->nullable();      // [{id, weight}]
            $table->json('participation_rules')->nullable(); // {lessons: bool, session_marks: bool}
            $table->boolean('allow_test_out')->default(false);
            $table->uuid('test_out_assessment_id')->nullable();
            $table->string('hours_mode', 8)->default('total');            // total | actual
            $table->string('certificate_types', 12)->default('pass');     // attendance | pass | both
            $table->decimal('attendance_certificate_min', 5, 2)->default(80);
            $table->boolean('survey_required_for_download')->default(true);
            $table->string('task_approval', 24)->default('trainer');      // trainer | trainer_then_supervisor | auto
            $table->json('certificate_templates')->nullable();            // {attendance: id, pass: id}
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['scope', 'scope_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('self_assessed')->default(false);
        });

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->string('trainer_decision', 16)->nullable();
            $table->foreignUuid('trainer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('trainer_decided_at')->nullable();
            $table->string('supervisor_decision', 16)->nullable();
            $table->foreignUuid('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('supervisor_decided_at')->nullable();
            $table->unsignedSmallInteger('returned_count')->default(0);
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->boolean('participated')->default(false);
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->decimal('participation_percent', 5, 2)->default(0);
            $table->decimal('weighted_score', 5, 2)->nullable();
            $table->string('pass_status', 12)->default('pending');   // pending | passed | failed | exempted
            $table->string('passed_via', 12)->nullable();            // standard | test_out | exception
            $table->timestamp('computed_at')->nullable();
        });

        Schema::create('pass_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->string('criterion', 24);
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['registration_id', 'criterion']);
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique('certificates_registration_id_unique');
        });
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('type', 12)->default('pass');            // attendance | pass
            $table->string('hours_mode', 8)->default('total');
            $table->decimal('hours_total', 6, 1)->nullable();
            $table->decimal('hours_actual', 6, 1)->nullable();
            $table->unique(['registration_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique(['registration_id', 'type']);
            $table->dropColumn(['type', 'hours_mode', 'hours_total', 'hours_actual']);
        });
        Schema::dropIfExists('pass_exceptions');
        Schema::table('registrations', fn (Blueprint $t) => $t->dropColumn(['participation_percent', 'weighted_score', 'pass_status', 'passed_via', 'computed_at']));
        Schema::table('attendance', fn (Blueprint $t) => $t->dropColumn('participated'));
        Schema::table('task_submissions', fn (Blueprint $t) => $t->dropColumn(['trainer_decision', 'trainer_id', 'trainer_decided_at', 'supervisor_decision', 'supervisor_id', 'supervisor_decided_at', 'returned_count']));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('self_assessed'));
        Schema::dropIfExists('passing_policies');
    }
};
