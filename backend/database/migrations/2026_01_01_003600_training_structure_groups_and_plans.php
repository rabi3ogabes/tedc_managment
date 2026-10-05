<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Tables that gain a nullable group (null = the whole program). */
    private const WITH_GROUP = ['program_sessions', 'registrations', 'waiting_lists', 'nominations', 'tasks', 'materials', 'certificates', 'attendance'];

    public function up(): void
    {
        // The annual plan comes first: groups point at plan items.
        Schema::create('training_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('title_ar');
            $table->string('title_en');
            $table->string('status', 16)->default('draft');   // draft | in_review | approved | active | closed
            $table->json('rules')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('baseline')->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['year', 'version']);
        });
        Schema::create('training_plan_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('training_plans')->cascadeOnDelete();
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title_ar');
            $table->string('title_en');
            $table->foreignUuid('category_id')->nullable()->constrained('program_categories')->nullOnDelete();
            $table->json('audience')->nullable();
            $table->string('priority', 12)->default('medium');
            $table->decimal('priority_score', 6, 2)->default(0);
            $table->unsignedSmallInteger('planned_groups')->default(1);
            $table->unsignedInteger('planned_seats')->default(0);
            $table->decimal('planned_hours', 8, 2)->default(0);
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();
            $table->string('source', 16)->default('manual');     // needs | manual | emergency | carry_over
            $table->json('source_refs')->nullable();
            $table->string('status', 16)->default('planned');    // planned | in_execution | done | postponed | cancelled
            $table->boolean('is_emergency')->default(false);
            $table->text('rationale_ar')->nullable();
            $table->text('rationale_en')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();
            $table->index(['plan_id', 'status']);
        });
        Schema::create('training_plan_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('training_plans')->cascadeOnDelete();
            $table->uuid('item_id')->nullable();
            $table->string('change_type', 16);                   // added | removed | modified | postponed | cancelled
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['plan_id', 'created_at']);
        });

        // Main program → optional sub-programs, with axes, emergency flag and school ownership (internal workshops).
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignUuid('parent_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('kind', 8)->default('main');            // main | sub
            $table->json('axes')->nullable();
            $table->boolean('is_emergency')->default(false);
            $table->text('emergency_reason')->nullable();
            $table->string('owner_type', 8)->default('center');    // center | school
            $table->foreignUuid('owner_school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('approval_status', 12)->default('approved');   // approved | pending | rejected
            $table->index(['parent_id']);
        });
        Schema::create('program_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title_ar');
            $table->string('title_en');
            $table->json('objectives')->nullable();
            $table->decimal('hours', 6, 2)->default(0);
            $table->text('summary_ar')->nullable();
            $table->text('summary_en')->nullable();
            $table->timestamps();
        });
        Schema::create('program_unit_skill', function (Blueprint $table) {
            $table->foreignUuid('program_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['program_unit_id', 'skill_id']);
        });

        Schema::create('training_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->string('code', 48)->unique();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('delivery_mode', 12)->default('in_person');   // in_person | online | blended | self_paced
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->unsignedInteger('capacity')->default(30);
            $table->unsignedTinyInteger('min_attendance_percent')->nullable();
            $table->foreignUuid('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('default_room_id')->nullable()->constrained('training_rooms')->nullOnDelete();
            $table->string('status', 20)->default('planned');
            $table->text('status_reason')->nullable();
            $table->date('postponed_to')->nullable();
            $table->foreignUuid('plan_item_id')->nullable()->constrained('training_plan_items')->nullOnDelete();
            $table->boolean('is_emergency')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['program_id', 'status']);
            $table->index(['status', 'start_date']);
        });
        Schema::create('group_trainers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained('training_groups')->cascadeOnDelete();
            $table->foreignUuid('trainer_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('lead');           // lead | assistant
            $table->decimal('hours', 6, 2)->default(0);
            $table->string('status', 12)->default('proposed');     // proposed | approved | rejected
            $table->json('form')->nullable();
            $table->timestamp('form_submitted_at')->nullable();
            $table->foreignUuid('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('external_approval_ref')->nullable();
            $table->string('external_approval_path')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'trainer_id']);
        });

        foreach (self::WITH_GROUP as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignUuid('training_group_id')->nullable()->constrained('training_groups')->nullOnDelete();
                $t->index('training_group_id');
            });
        }

        $this->backfill();
    }

    /** Every existing program becomes one group: dates, capacity, window and status carry over, and all rows point at it. */
    private function backfill(): void
    {
        $status = ['draft' => 'planned', 'published' => 'planned', 'registration_open' => 'registration_open', 'in_progress' => 'ongoing', 'completed' => 'completed', 'archived' => 'completed', 'cancelled' => 'cancelled'];
        $mode = ['in_person' => 'in_person', 'online' => 'online', 'hybrid' => 'blended'];
        foreach (DB::table('programs')->get() as $p) {
            DB::table('training_groups')->insert([
                'id' => (string) Str::uuid7(), 'program_id' => $p->id, 'code' => $p->code.'-G1', 'sequence' => 1,
                'delivery_mode' => $mode[$p->delivery_mode] ?? 'in_person', 'start_date' => $p->start_date, 'end_date' => $p->end_date,
                'registration_opens_at' => $p->registration_opens_at, 'registration_closes_at' => $p->registration_closes_at,
                'capacity' => $p->capacity, 'min_attendance_percent' => $p->min_attendance_percent, 'status' => $status[$p->status] ?? 'planned',
                'published_at' => in_array($p->status, ['draft'], true) ? null : $p->created_at, 'created_at' => $p->created_at, 'updated_at' => $p->updated_at, 'deleted_at' => $p->deleted_at,
            ]);
        }
        foreach (['program_sessions', 'registrations', 'waiting_lists', 'nominations', 'certificates'] as $table) {
            DB::statement("UPDATE {$table} SET training_group_id = (SELECT g.id FROM training_groups g WHERE g.program_id = {$table}.program_id ORDER BY g.sequence LIMIT 1)");
        }
        DB::statement('UPDATE attendance SET training_group_id = (SELECT s.training_group_id FROM program_sessions s WHERE s.id = attendance.program_session_id)');
    }

    public function down(): void
    {
        foreach (self::WITH_GROUP as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['training_group_id']);
                $t->dropConstrainedForeignId('training_group_id');
            });
        }
        Schema::dropIfExists('group_trainers');
        Schema::dropIfExists('training_groups');
        Schema::dropIfExists('program_unit_skill');
        Schema::dropIfExists('program_units');
        Schema::table('programs', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('owner_school_id');
            $table->dropColumn(['kind', 'axes', 'is_emergency', 'emergency_reason', 'owner_type', 'approval_status']);
        });
        Schema::dropIfExists('training_plan_changes');
        Schema::dropIfExists('training_plan_items');
        Schema::dropIfExists('training_plans');
    }
};
