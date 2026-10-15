<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survey Studio v2, Gate 4: how a survey reaches people beyond the signed-in audience. A public link (slug + QR) with a
 * duplicate rule, single-use invitation tokens sent by e-mail, and the files respondents attach to file-upload answers.
 * Secrets are stored hashed; a link is revoked by regenerating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_distributions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('channel', 16)->default('public');          // public, invitation
            $table->string('slug', 24)->unique();                        // the public address
            $table->string('duplicate_rule', 16)->default('device');     // open, device, email_code
            $table->unsignedInteger('max_responses')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('qr_version')->default(1);
            $table->unsignedInteger('submissions_count')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['survey_id', 'channel']);
        });

        Schema::create('survey_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignUuid('distribution_id')->nullable()->constrained('survey_distributions')->nullOnDelete();
            $table->string('email', 190);
            $table->string('name', 120)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->string('send_status', 16)->default('pending');       // pending, sent, failed, manual
            $table->string('send_error', 300)->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['survey_id', 'email']);
        });

        Schema::create('survey_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('respondent_key', 64);                        // who uploaded (the same key as the draft)
            $table->string('question_id', 40);
            $table->foreignUuid('submission_id')->nullable()->constrained('survey_submissions')->nullOnDelete();
            $table->string('bucket', 32)->default('surveys');
            $table->string('path', 300);
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
            $table->index(['survey_id', 'respondent_key']);
        });
    }

    public function down(): void
    {
        foreach (['survey_files', 'survey_invitations', 'survey_distributions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
