<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 03 — needs cycle, department proposals, manager requests, individual needs, performance data,
 * rule-based needs, the competency framework with required levels per job, and instrument approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 48)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('skills', function (Blueprint $table) {
            $table->foreignUuid('domain_id')->nullable()->constrained('competency_domains')->nullOnDelete();
            $table->string('framework_version', 16)->nullable();
            $table->json('descriptors')->nullable();
            $table->boolean('licence_relevant')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('job_competency_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('job_title_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->string('education_stage', 32)->nullable();
            $table->string('subject', 80)->nullable();
            $table->unsignedTinyInteger('required_level')->default(3);
            $table->unsignedTinyInteger('weight')->default(1);
            $table->timestamps();
            $table->index(['job_title_id', 'skill_id']);
        });

        Schema::create('needs_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('year');
            $table->string('title_ar');
            $table->string('title_en');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->string('status', 12)->default('draft');   // draft | open | closed | analysed
            $table->foreignUuid('plan_id')->nullable()->constrained('training_plans')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->timestamp('closing_reminded_at')->nullable();
            $table->timestamps();
            $table->index(['year', 'status']);
        });

        Schema::create('program_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('needs_cycles')->cascadeOnDelete();
            $table->string('entity_type', 12)->default('department');   // department | school | section
            $table->uuid('entity_id')->nullable();
            $table->string('entity_name')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('program_title_ar');
            $table->string('program_title_en')->nullable();
            $table->foreignUuid('existing_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->unsignedSmallInteger('groups_count')->default(1);
            $table->json('axes')->nullable();
            $table->json('target_job_title_ids')->nullable();
            $table->text('target_description')->nullable();
            $table->unsignedSmallInteger('days')->default(1);
            $table->decimal('hours', 6, 2)->default(0);
            $table->string('kit_availability', 8)->default('none');   // available | partial | none
            $table->string('kit_attachment_path')->nullable();
            $table->json('trainer_nominations')->nullable();
            $table->unsignedTinyInteger('importance')->default(3);
            $table->unsignedSmallInteger('priority_rank')->nullable();
            $table->text('justification')->nullable();
            $table->string('status', 12)->default('submitted');       // submitted | under_review | accepted | merged | rejected
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->uuid('plan_item_id')->nullable();
            $table->timestamps();
            $table->index(['cycle_id', 'status']);
        });

        Schema::create('institutional_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->nullable()->constrained('needs_cycles')->nullOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('entity_id')->nullable();
            $table->string('entity_name')->nullable();
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->unsignedTinyInteger('need_degree')->default(3);
            $table->json('objectives')->nullable();
            $table->json('employee_ids')->nullable();
            $table->string('preferred_window')->nullable();
            $table->string('status', 12)->default('submitted');       // submitted | accepted | merged | rejected
            $table->text('review_note')->nullable();
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('plan_item_id')->nullable();
            $table->timestamps();
            $table->index(['cycle_id', 'status']);
        });

        Schema::create('individual_needs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);   // self_survey | self | manager | appraisal | observation | new_hire | specialisation | licence | test
            $table->json('source_ref')->nullable();
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->unsignedTinyInteger('required_level')->nullable();
            $table->unsignedTinyInteger('gap')->default(0);
            $table->decimal('priority_score', 8, 2)->default(0);
            $table->text('explanation_ar')->nullable();
            $table->text('explanation_en')->nullable();
            $table->string('status', 16)->default('pending_manager');  // pending_manager | approved | rejected | auto_approved | fulfilled
            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('manager_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUuid('cycle_id')->nullable()->constrained('needs_cycles')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'skill_id', 'source']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('performance_appraisals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('rating_code', 16);   // excellent | very_good | good | acceptable | weak
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('source', 12)->default('import');
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'year']);
        });

        Schema::create('classroom_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('observer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observer_role', 24)->nullable();
            $table->date('observed_on');
            $table->string('subject', 80)->nullable();
            $table->string('grade', 24)->nullable();
            $table->json('scores')->nullable();     // {skill_id: level}
            $table->unsignedTinyInteger('overall')->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 12)->default('import');
            $table->timestamps();
            $table->index(['employee_id', 'observed_on']);
        });

        Schema::create('needs_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('trigger', 16);   // new_hire | appraisal | observation | specialisation | stage | licence | test
            $table->json('conditions')->nullable();
            $table->json('action')->nullable();   // {skill_ids: [], required_level, priority_boost}
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::table('needs_surveys', function (Blueprint $table) {
            $table->string('approval_status', 10)->default('draft');   // draft | pending | approved | returned
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
        });
        // Surveys that already exist were in use before approval was required: keep them working.
        DB::table('needs_surveys')->update(['approval_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('needs_surveys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at', 'approval_note']);
        });
        foreach (['needs_rules', 'classroom_observations', 'performance_appraisals', 'individual_needs', 'institutional_requests', 'program_proposals', 'needs_cycles', 'job_competency_requirements'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('skills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('domain_id');
            $table->dropColumn(['framework_version', 'descriptors', 'licence_relevant', 'is_active']);
        });
        Schema::dropIfExists('competency_domains');
    }
};
