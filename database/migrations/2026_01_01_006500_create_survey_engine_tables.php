<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survey engine (Survey Studio v2, Gate 2): one definition model with immutable published versions,
 * three privacy modes, a participation registry that never links to anonymous answers, autosaved
 * drafts, an edit history and a domain event trail. Distribution links, invitations and file answers
 * arrive with Gate 4; analytics and exports with Gate 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 32)->default('general')->index();
            $table->string('title_ar', 200);
            $table->string('title_en', 200)->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('status', 16)->default('draft')->index(); // draft, in_review, approved, published, closed, archived
            $table->string('privacy_mode', 16)->default('identified'); // identified, confidential, anonymous
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->uuid('current_version_id')->nullable();
            $table->json('questions');                 // the working draft; published copies live in survey_versions
            $table->json('audience')->nullable();      // SurveyAudience filters
            $table->json('settings')->nullable();
            $table->string('approved_checksum', 64)->nullable(); // the draft checksum the approver saw
            $table->text('review_note')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Qatar');
            $table->unsignedInteger('retention_days')->nullable();
            $table->text('pseudonym_key')->nullable(); // encrypted; deleted after close + grace (Gate 7)
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('legacy_type', 64)->nullable(); // source engine during migration (Gate 5)
            $table->uuid('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('survey_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('questions');
            $table->json('settings')->nullable(); // respondent-facing settings at publish time
            $table->string('checksum', 64);
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['survey_id', 'number']);
        });

        // Who was invited and whether they answered. Deliberately separate from the answers so that
        // anonymous surveys can enforce one-answer-per-person without linking a person to a response.
        Schema::create('survey_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['survey_id', 'user_id']);
        });

        Schema::create('survey_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->uuid('version_id');
            $table->string('respondent_key', 64); // user id (identified) or an HMAC (confidential / anonymous)
            $table->json('draft_answers')->nullable();
            $table->unsignedSmallInteger('page')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['survey_id', 'respondent_key']);
        });

        Schema::create('survey_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignUuid('version_id')->constrained('survey_versions')->cascadeOnDelete();
            $table->foreignUuid('respondent_user_id')->nullable()->constrained('users')->nullOnDelete(); // identified only
            $table->string('respondent_pseudonym', 64)->nullable();                                       // confidential only
            $table->json('segment_snapshot')->nullable();                                                 // only the fields the mode allows
            $table->json('answers');
            $table->string('status', 16)->default('submitted');
            $table->string('source', 16)->default('web');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('edited_at')->nullable();
            $table->unsignedSmallInteger('edit_count')->default(0);
            $table->timestamps();
            $table->index(['survey_id', 'respondent_user_id']);
            $table->index(['survey_id', 'respondent_pseudonym']);
        });

        Schema::create('survey_submission_edits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('survey_submissions')->cascadeOnDelete();
            $table->uuid('version_id');
            $table->json('previous_answers');
            $table->timestamp('created_at');
        });

        Schema::create('survey_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40)->index();
            $table->json('data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['survey_events', 'survey_submission_edits', 'survey_submissions', 'survey_sessions', 'survey_participants', 'survey_versions', 'surveys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
