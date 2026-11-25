<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08 — evaluation forms and assignments for the instruments that had no home (trainer self-reflection,
 * planning-team evaluation, supervisor and specialist feedback), interviews, program evaluation reports, satisfaction
 * alerts and evidence on the impact forms. The satisfaction survey, the trainee impact survey and the manager's impact
 * form keep their own tables (adapters): historical data keeps reporting exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_forms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 24);       // satisfaction | impact_trainee | impact_manager | trainer_reflection | planning_evaluation | supervisor_feedback | specialist_feedback | custom
            $table->string('title_ar');
            $table->string('title_en');
            $table->json('questions');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('approval_status', 10)->default('draft');   // draft | pending | approved | returned
            $table->text('approval_note')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);              // the adapters over the older tables: shown, not edited
            $table->json('settings')->nullable();                      // {anonymous, evidence, max_files, required_for_certificate}
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('kind');
        });

        Schema::create('evaluation_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_id')->constrained('evaluation_forms')->cascadeOnDelete();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->nullOnDelete();
            $table->string('respondent_type', 20);   // trainee | manager | trainer | supervisor | planning_specialist | planning_member
            $table->foreignUuid('respondent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('subject_registration_id')->nullable()->constrained('registrations')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->string('status', 10)->default('pending');          // pending | submitted | expired
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['respondent_user_id', 'status']);
            $table->unique(['form_id', 'group_id', 'respondent_user_id', 'subject_registration_id'], 'evaluation_assignment_once');
        });

        Schema::create('evaluation_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assignment_id')->unique()->constrained('evaluation_assignments')->cascadeOnDelete();
            $table->json('answers');
            $table->json('evidence')->nullable();                      // {questionId: [{type: file|link, name, path|url}]}
            $table->unsignedSmallInteger('form_version')->default(1);
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
        });

        Schema::create('evaluation_interviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->nullOnDelete();
            $table->foreignUuid('interviewee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('interviewee_name')->nullable();
            $table->foreignUuid('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('held_at');
            $table->string('method', 10)->default('in_person');        // in_person | online | phone
            $table->json('questions_answers')->nullable();
            $table->text('summary')->nullable();
            $table->json('attachments')->nullable();
            $table->string('sentiment', 10)->default('neutral');       // positive | neutral | negative
            $table->timestamps();
            $table->index(['program_id', 'held_at']);
        });

        Schema::create('program_evaluation_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->nullOnDelete();
            $table->string('period', 40)->nullable();
            $table->json('metrics');
            $table->json('qualitative')->nullable();                   // {strengths: [], improvements: []}
            $table->string('classification', 20);                      // successful_continue | needs_review | weak_stop
            $table->json('classification_reasons')->nullable();
            $table->text('recommendations_ar')->nullable();
            $table->text('recommendations_en')->nullable();
            $table->string('status', 10)->default('draft');            // draft | reviewed | approved
            $table->foreignUuid('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['program_id', 'status']);
        });

        Schema::create('satisfaction_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->cascadeOnDelete();
            $table->decimal('response_rate', 5, 2);
            $table->decimal('average', 5, 2);
            $table->decimal('threshold', 5, 2);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'group_id']);
        });

        Schema::table('impact_surveys', fn (Blueprint $table) => $table->json('evidence')->nullable());
        Schema::table('supervisor_evaluations', fn (Blueprint $table) => $table->json('evidence')->nullable());
    }

    public function down(): void
    {
        Schema::table('supervisor_evaluations', fn (Blueprint $table) => $table->dropColumn('evidence'));
        Schema::table('impact_surveys', fn (Blueprint $table) => $table->dropColumn('evidence'));
        foreach (['satisfaction_alerts', 'program_evaluation_reports', 'evaluation_interviews', 'evaluation_responses', 'evaluation_assignments', 'evaluation_forms'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
