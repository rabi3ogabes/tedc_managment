<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TYP-12 offline learning in the app: the devices that keep course content (each with its own content key, stored
 * encrypted), a checksum per downloadable file, assessments that may be taken offline (and practice that adapts,
 * AI-03), the single-use packages issued for offline exams, and the timing the app reports for an offline attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->string('platform', 20)->nullable();
            $table->string('name', 120)->nullable();
            $table->text('content_key');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_id']);
        });

        Schema::table('course_lessons', function (Blueprint $table) {
            $table->string('file_sha256', 64)->nullable();
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->boolean('allow_offline')->default(false);
            $table->unsignedSmallInteger('offline_window_hours')->default(72);
            $table->boolean('adaptive')->default(false);
        });

        Schema::create('offline_exam_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->string('nonce', 64)->unique();
            $table->json('question_ids');
            $table->json('questions');
            $table->unsignedInteger('seed');
            $table->timestamp('used_at')->nullable();
            $table->uuid('attempt_id')->nullable();
            $table->timestamps();
            $table->index(['registration_id', 'assessment_id']);
        });

        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->unsignedInteger('device_elapsed_seconds')->nullable();
            $table->timestamp('client_started_at')->nullable();
        });

        // The time of the last offline event applied to a lesson: offline events are ordered and timed against each
        // other, never against what the person did online in between.
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->timestamp('offline_last_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', fn (Blueprint $table) => $table->dropColumn('offline_last_at'));
        Schema::table('assessment_attempts', fn (Blueprint $table) => $table->dropColumn(['device_elapsed_seconds', 'client_started_at']));
        Schema::dropIfExists('offline_exam_tokens');
        Schema::table('assessments', fn (Blueprint $table) => $table->dropColumn(['allow_offline', 'offline_window_hours', 'adaptive']));
        Schema::table('course_lessons', fn (Blueprint $table) => $table->dropColumn('file_sha256'));
        Schema::dropIfExists('offline_devices');
    }
};
