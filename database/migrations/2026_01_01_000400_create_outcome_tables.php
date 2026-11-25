<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning outcomes: tasks, submissions, evaluations, supervisor feedback,
 * impact follow-up surveys and certificates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('program_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('instructions_en')->nullable();
            $table->text('instructions_ar')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->json('submission_types');    // pdf, word, image, text
            $table->unsignedSmallInteger('max_file_mb')->default(20);
            $table->boolean('is_required')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('task_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->text('text_response')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime', 128)->nullable();
            $table->string('status', 24)->default('submitted')->index(); // submitted, approved, rejected, changes_requested
            $table->text('feedback')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['task_id', 'registration_id']);
        });

        Schema::create('evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('registration_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->json('ratings');               // {content: 5, trainer: 4, organization: 5, relevance: 4, ...}
            $table->decimal('satisfaction_score', 5, 2);   // Kirkpatrick L1 (0..100)
            $table->decimal('pre_test_score', 5, 2)->nullable();
            $table->decimal('post_test_score', 5, 2)->nullable(); // Kirkpatrick L2
            $table->text('comments')->nullable();
            $table->boolean('allow_testimonial')->default(false);
            $table->timestamp('submitted_at');
            $table->timestamps();
        });

        Schema::create('supervisor_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('application_score');   // 0..100
            $table->text('behavior_change')->nullable();
            $table->text('comments')->nullable();
            $table->text('recommendations')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
        });

        Schema::create('impact_surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('stage_days'); // 30, 60, 90
            $table->date('scheduled_for')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 16)->default('scheduled')->index(); // scheduled, sent, completed, expired
            $table->string('applied_learning', 16)->nullable(); // yes, partially, no
            $table->unsignedTinyInteger('application_score')->nullable(); // 0..100
            $table->text('changes_observed')->nullable();
            $table->json('skills_improved')->nullable();
            $table->boolean('needs_support')->nullable();
            $table->text('support_details')->nullable();
            $table->timestamps();
            $table->unique(['registration_id', 'stage_days']);
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('certificate_no', 32)->unique();
            $table->string('verification_code', 32)->unique();
            $table->foreignUuid('registration_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->timestamp('issued_at');
            $table->decimal('hours', 6, 1);
            $table->string('file_path')->nullable();
            $table->string('status', 16)->default('valid'); // valid, revoked
            $table->string('revoked_reason')->nullable();
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['certificates', 'impact_surveys', 'supervisor_evaluations', 'evaluations', 'task_submissions', 'tasks'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
