<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 09 — career and licence paths, professional-development records and knowledge transfer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_paths', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 14);                       // promotion | licence | specialisation
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->json('job_title_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('career_path_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('path_id')->constrained('career_paths')->cascadeOnDelete();
            $table->unsignedSmallInteger('level_no');
            $table->string('title_ar');
            $table->string('title_en');
            $table->json('conditions')->nullable();           // [{field, operator, value}] in the eligibility-rule syntax
            $table->json('required_programs')->nullable();    // [{program_ids: [...], min: n}] "any N of" groups
            $table->decimal('min_pd_hours', 7, 1)->default(0);
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->json('renewal_conditions')->nullable();
            $table->timestamps();
            $table->unique(['path_id', 'level_no']);
        });

        Schema::create('employee_path_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('path_id')->constrained('career_paths')->cascadeOnDelete();
            $table->unsignedSmallInteger('current_level_no')->default(0);
            $table->unsignedSmallInteger('target_level_no')->nullable();
            $table->string('status', 12)->default('not_started');  // not_started | in_progress | eligible | achieved | expired | lost
            $table->json('explanation')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'path_id']);
        });

        Schema::create('professional_licences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('path_id')->nullable()->constrained('career_paths')->nullOnDelete();
            $table->unsignedSmallInteger('level_no');
            $table->string('licence_no', 40);
            $table->date('issued_at');
            $table->date('expires_at')->nullable();
            $table->string('status', 10)->default('active');       // active | expired | suspended
            $table->string('source', 16)->default('manual');      // licences_system | manual | platform
            $table->timestamp('synced_at')->nullable();
            $table->json('reminded')->nullable();                  // days already reminded: [90, 60]
            $table->timestamps();
            $table->unique(['employee_id', 'licence_no']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('pd_activity_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->json('hour_rules');                            // {level: {factor, cap_activity}, cap_year}
            $table->boolean('evidence_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pd_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('pd_activity_types');
            $table->string('title');
            $table->string('provider')->nullable();
            $table->string('domain', 60)->nullable();
            $table->json('skill_ids')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->decimal('duration_hours', 6, 1);
            $table->string('participation_level', 10)->default('attendee');   // attendee | presenter | organiser | author
            $table->string('location')->nullable();
            $table->json('evidence')->nullable();
            $table->decimal('computed_hours', 6, 1)->default(0);
            $table->decimal('approved_hours', 6, 1)->nullable();
            $table->string('status', 16)->default('draft');        // draft | pending_manager | approved | rejected | returned
            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('manager_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->boolean('recognition_request')->default(false);
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('pd_recognition_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pd_activity_id')->constrained('pd_activities')->cascadeOnDelete();
            $table->foreignUuid('requested_equivalent_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('center_decision', 10)->nullable();     // approved | rejected
            $table->decimal('recognised_hours', 6, 1)->nullable();
            $table->json('equivalent_program_ids')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pd_annual_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('year');
            $table->json('audience')->nullable();                  // {job_title_ids: [], job_categories: []}
            $table->decimal('min_hours', 6, 1);
            $table->json('counts')->nullable();                    // {center: bool, internal: bool, external: bool, knowledge_transfer: bool, caps: {source: hours}}
            $table->timestamps();
            $table->index('year');
        });

        Schema::create('knowledge_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('due_on')->nullable();
            $table->date('delivered_on')->nullable();
            $table->decimal('hours', 5, 1)->default(0);
            $table->unsignedSmallInteger('beneficiary_count')->default(0);
            $table->json('beneficiaries')->nullable();            // [{employee_id?, name?}]
            $table->string('method', 10)->nullable();             // workshop | meeting | coaching | online
            $table->json('evidence')->nullable();
            $table->string('status', 14)->default('pending');     // pending | pending_review | approved | rejected
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->unique('registration_id');
        });

        Schema::table('programs', fn (Blueprint $table) => $table->json('knowledge_transfer')->nullable());
    }

    public function down(): void
    {
        Schema::table('programs', fn (Blueprint $table) => $table->dropColumn('knowledge_transfer'));
        foreach (['knowledge_transfers', 'pd_annual_targets', 'pd_recognition_requests', 'pd_activities', 'pd_activity_types', 'professional_licences', 'employee_path_progress', 'career_path_levels', 'career_paths'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
