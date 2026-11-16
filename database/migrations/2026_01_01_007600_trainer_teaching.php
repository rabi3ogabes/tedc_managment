<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The trainer's side of a programme: the notice that tells a trainer they were assigned (shown as a popup on their
 * home until they ask not to see it again), and the log of everything done with a training kit from the trainer's
 * reader and presenter — opened, presented, acknowledged, and attempts to capture the screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The trainer-assigned notice now carries the whole sentence (dates, group, room, supervisor): an untouched template follows it.
        DB::table('notification_templates')->where('event', 'trainer.assigned')->where('body_ar', 'like', 'أنت مدرب برنامج%')->update(['body_ar' => '{{detail}}', 'body_en' => '{{detail}}']);

        Schema::create('trainer_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->nullOnDelete();
            $table->string('scope_key', 64);                              // the group id, or '' for the programme as a whole: one notice per trainer and scope
            $table->string('kind', 24)->default('assigned');
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();                // "don't show again"
            $table->timestamps();
            $table->unique(['user_id', 'program_id', 'scope_key', 'kind']);
            $table->index(['user_id', 'dismissed_at']);
        });

        Schema::create('kit_access_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('kit_id')->constrained('training_kits')->cascadeOnDelete();
            $table->foreignUuid('kit_file_id')->nullable()->constrained('kit_files')->nullOnDelete();
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('program_sessions')->nullOnDelete();
            $table->string('kind', 24);                                   // acknowledged | opened | presented | capture_attempt | hidden | print_attempt | blocked | tamper
            $table->json('meta')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['kit_id', 'created_at']);
            $table->index(['user_id', 'kind']);
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_access_events');
        Schema::dropIfExists('trainer_notices');
    }
};
