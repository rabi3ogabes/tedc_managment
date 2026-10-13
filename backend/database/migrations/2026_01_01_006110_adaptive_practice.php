<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-03 adaptive practice: the trail of an adaptive attempt (the questions still available at each level, the
 * staircase per competency and the reason for every question), and the review lessons assigned to the learners who
 * need them — open until the competency is mastered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->json('adaptive_log')->nullable();
        });

        Schema::create('remedial_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->float('mastery')->nullable();          // when it was assigned, 0..1
            $table->timestamp('assigned_at');
            $table->timestamp('cleared_at')->nullable();
            $table->float('cleared_mastery')->nullable();
            $table->timestamps();
            $table->unique(['registration_id', 'lesson_id']);
            $table->index(['lesson_id', 'cleared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remedial_assignments');
        Schema::table('assessment_attempts', fn (Blueprint $table) => $table->dropColumn('adaptive_log'));
    }
};
