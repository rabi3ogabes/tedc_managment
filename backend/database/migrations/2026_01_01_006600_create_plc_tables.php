<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Professional learning communities (PLC), Phase 1: the community frame across schools, the register of
 * incoming needs and proposals (راصد, the centre, another entity, a school), the PLC fields of a workshop
 * (a programme), its host and participating schools, several trainers per session, and the decision log.
 * Existing school-internal workshops are attached to the engine as the internal scenario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plc_communities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('title_ar', 200);
            $table->string('title_en', 200)->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('domain', 64)->nullable()->index();
            $table->string('status', 16)->default('active')->index(); // active, archived
            $table->foreignUuid('lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('host_school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignUuid('space_id')->nullable()->constrained('spaces')->nullOnDelete(); // optional discussion space
            $table->json('settings')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('plc_community_schools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('plc_communities')->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('role', 16)->default('member'); // member, host
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'school_id']);
        });

        Schema::create('plc_needs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source', 16)->index();                  // rased, centre, entity, school
            $table->string('reference_no', 64)->nullable()->index(); // راصد reference when known
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->string('domain', 64)->nullable();
            $table->string('target_audience', 200)->nullable();
            $table->date('received_at')->nullable();
            $table->string('review_status', 16)->default('new')->index(); // new, under_review, converted, declined
            $table->string('source_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->json('school_ids')->nullable();
            $table->string('entity_name', 200)->nullable();
            $table->string('proposer_name', 200)->nullable();
            $table->text('justification')->nullable();
            $table->text('expected_outcomes')->nullable();
            $table->json('attachments')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('plc_workshops', function (Blueprint $table) {
            $table->foreignUuid('program_id')->primary()->constrained('programs')->cascadeOnDelete();
            $table->foreignUuid('community_id')->nullable()->constrained('plc_communities')->nullOnDelete();
            $table->foreignUuid('need_id')->nullable()->constrained('plc_needs')->nullOnDelete();
            $table->string('source', 16)->index();              // rased, centre, entity, school — frozen after approval
            $table->string('source_ref', 64)->nullable();
            $table->string('organiser', 200)->nullable();       // centre or the organising entity
            $table->foreignUuid('coordinator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 16)->default('normal');  // low, normal, high, urgent
            $table->unsignedSmallInteger('expected_participants')->nullable();
            $table->text('prerequisites')->nullable();
            $table->text('outcomes')->nullable();
            $table->string('target_audience', 200)->nullable();
            $table->string('domain', 64)->nullable();
            $table->string('scenario', 16)->default('shared')->index(); // shared, internal
            $table->foreignUuid('host_school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('workflow_state', 24)->default('draft')->index();
            $table->timestamp('state_changed_at')->nullable();
            $table->json('approval_path')->nullable();          // [{key, permission, status, by, at, note}]
            $table->text('decision_note')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
        });

        Schema::create('plc_workshop_schools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('role', 16)->default('participant'); // host, participant
            $table->unsignedSmallInteger('seats')->nullable();
            $table->string('status', 16)->default('selected');  // suggested, selected, excluded, approved
            $table->json('suggestion_reasons')->nullable();
            $table->decimal('distance_km', 6, 2)->nullable();   // only when computed from official coordinates
            $table->string('excluded_reason', 64)->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['program_id', 'school_id']);
        });

        Schema::create('plc_session_trainers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_session_id')->constrained('program_sessions')->cascadeOnDelete();
            $table->foreignUuid('trainer_id')->constrained('trainers')->cascadeOnDelete();
            $table->string('role', 8)->default('lead'); // lead, co
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->string('status', 16)->default('planned'); // planned, confirmed, replaced
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['program_session_id', 'trainer_id']);
        });

        Schema::create('plc_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type', 64);
            $table->uuid('subject_id');
            $table->string('kind', 40)->index();     // transition, school_choice, trainer_change, exception, schedule_change ...
            $table->string('decision', 40);
            $table->text('reason')->nullable();
            $table->json('data')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
        });

        // Existing school-internal workshops become the internal scenario of the engine (reversible: dropping the table).
        $now = now();
        $states = ['pending' => 'submitted', 'approved' => 'approved', 'rejected' => 'rejected'];
        DB::table('programs')->where('owner_type', 'school')->whereNull('deleted_at')->orderBy('created_at')->chunk(500, function ($rows) use ($now, $states) {
            $insert = [];
            foreach ($rows as $p) {
                $state = $states[$p->approval_status ?? 'pending'] ?? 'draft';
                if ($state === 'approved' && in_array($p->status, ['in_progress'], true)) {
                    $state = 'in_progress';
                } elseif ($state === 'approved' && in_array($p->status, ['completed', 'archived'], true)) {
                    $state = 'closed';
                } elseif ($p->status === 'cancelled') {
                    $state = 'cancelled';
                }
                $insert[] = [
                    'program_id' => $p->id, 'source' => 'school', 'scenario' => 'internal', 'host_school_id' => $p->owner_school_id,
                    'coordinator_user_id' => $p->created_by, 'workflow_state' => $state, 'state_changed_at' => $p->updated_at ?? $now,
                    'approval_path' => json_encode([['key' => 'centre', 'permission' => 'workshops.approve', 'status' => $state === 'submitted' ? 'pending' : ($state === 'rejected' ? 'rejected' : 'approved')]]),
                    'created_at' => $p->created_at ?? $now, 'updated_at' => $now,
                ];
            }
            if ($insert) {
                DB::table('plc_workshops')->insertOrIgnore($insert);
                DB::table('plc_workshop_schools')->insertOrIgnore(array_map(fn ($r) => [
                    'id' => (string) Str::uuid(), 'program_id' => $r['program_id'], 'school_id' => $r['host_school_id'], 'role' => 'host', 'status' => 'approved',
                    'created_at' => $now, 'updated_at' => $now,
                ], array_filter($insert, fn ($r) => $r['host_school_id'])));
            }
        });
    }

    public function down(): void
    {
        foreach (['plc_decisions', 'plc_session_trainers', 'plc_workshop_schools', 'plc_workshops', 'plc_needs', 'plc_community_schools', 'plc_communities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
