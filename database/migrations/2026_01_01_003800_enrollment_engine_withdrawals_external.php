<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 04 — seat allocation per entity, priority rules, equivalent programs, extra targeting fields,
 * two-stage approval, withdrawal workflows and external-user registration forms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('experience_moe_years', 4, 1)->nullable();
            $table->decimal('experience_outside_years', 4, 1)->nullable();
            $table->date('current_title_since')->nullable();
            $table->string('grade_level', 24)->nullable();
            $table->json('subjects')->nullable();
            $table->json('grades_taught')->nullable();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->string('repeat_policy', 8)->default('block');   // block | warn | allow
        });

        Schema::table('training_groups', function (Blueprint $table) {
            $table->string('approval_mode', 20)->default('manager_then_center');   // manager_then_center | center_only | auto
            $table->boolean('approve_after_window')->default(true);
            $table->boolean('allow_overlap_until_approved')->default(true);
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_decided_at')->nullable();
            $table->text('manager_note')->nullable();
            $table->foreignUuid('center_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('center_decided_at')->nullable();
            $table->string('seat_entity_type', 16)->nullable();
            $table->uuid('seat_entity_id')->nullable();
            $table->decimal('priority_score', 8, 2)->nullable();
            $table->json('priority_explanation')->nullable();
        });

        Schema::create('group_seat_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained('training_groups')->cascadeOnDelete();
            $table->string('entity_type', 16);   // school | department | school_group | job_group | open
            $table->uuid('entity_id')->nullable();
            $table->unsignedInteger('seats');
            $table->timestamp('release_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();
            $table->index(['group_id', 'entity_type', 'entity_id']);
        });

        Schema::create('registration_priority_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 8)->default('global');   // global | program | group
            $table->uuid('scope_id')->nullable();
            $table->json('criteria');
            $table->json('weights')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('program_equivalences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('equivalent_program_id')->constrained('programs')->cascadeOnDelete();
            $table->boolean('bidirectional')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'equivalent_program_id']);
        });

        Schema::create('withdrawal_reasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('label_ar');
            $table->string('label_en');
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach ([['illness', 'مرض أو ظرف صحي', 'Illness or health reason', true], ['workload', 'ضغط العمل', 'Work load', false], ['schedule_clash', 'تعارض في الجدول', 'Schedule clash', false], ['travel', 'سفر أو إجازة', 'Travel or leave', false], ['other', 'سبب آخر', 'Other reason', false]] as $i => [$code, $ar, $en, $att]) {
            DB::table('withdrawal_reasons')->insert(['id' => (string) Str::uuid(), 'code' => $code, 'label_ar' => $ar, 'label_en' => $en, 'requires_attachment' => $att, 'is_active' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 40)->nullable();
            $table->text('reason_text')->nullable();
            $table->json('attachments')->nullable();
            $table->string('timing', 16);        // during_window | before_start | after_start
            $table->string('stage', 12)->default('manager');   // manager | supervisor | done
            $table->string('status', 12)->default('pending');  // pending | approved | rejected | cancelled
            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('manager_decision', 10)->nullable();
            $table->text('manager_note')->nullable();
            $table->foreignUuid('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('supervisor_decision', 10)->nullable();
            $table->text('supervisor_note')->nullable();
            $table->boolean('is_late')->default(false);
            $table->timestamps();
            $table->index(['status', 'stage']);
        });

        Schema::create('registration_forms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('intro_ar')->nullable();
            $table->text('intro_en')->nullable();
            $table->string('audience', 12)->default('trainee');   // trainee | trainer | other
            $table->json('fields');
            $table->json('conditions')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('registration_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 20)->unique();
            $table->foreignUuid('form_id')->constrained('registration_forms')->cascadeOnDelete();
            $table->json('data');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('national_id_hash', 64)->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('status', 12)->default('submitted');   // submitted | needs_info | approved | rejected
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('snapshot_path')->nullable();
            $table->timestamps();
            $table->index(['form_id', 'status']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        foreach (['registration_requests', 'registration_forms', 'withdrawal_requests', 'withdrawal_reasons', 'program_equivalences', 'registration_priority_rules', 'group_seat_allocations'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropConstrainedForeignId('center_decided_by');
            $table->dropColumn(['manager_decided_at', 'manager_note', 'center_decided_at', 'seat_entity_type', 'seat_entity_id', 'priority_score', 'priority_explanation']);
        });
        Schema::table('training_groups', fn (Blueprint $t) => $t->dropColumn(['approval_mode', 'approve_after_window', 'allow_overlap_until_approved']));
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('repeat_policy'));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['experience_moe_years', 'experience_outside_years', 'current_title_since', 'grade_level', 'subjects', 'grades_taught']));
    }
};
