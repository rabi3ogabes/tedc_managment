<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training needs, communication, reporting and audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_needs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('skill_name');
            $table->unsignedInteger('employees_count');
            $table->string('priority', 16)->index();     // low, medium, high, critical
            $table->text('reason');
            $table->foreignUuid('target_job_title_id')->nullable()->constrained('job_titles')->nullOnDelete();
            $table->string('target_group')->nullable();
            $table->string('status', 16)->default('submitted')->index(); // submitted, under_review, approved, planned, fulfilled, rejected
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 16)->default('announcement'); // news, announcement, circular
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('body_en')->nullable();
            $table->text('body_ar')->nullable();
            $table->string('audience', 16)->default('all');   // all, schools, programs, roles
            $table->json('target_ids')->nullable();
            $table->json('attachments')->nullable();          // [{type: file|video|link, title, url|path}]
            $table->string('cover_path')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48)->index();
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('body_en')->nullable();
            $table->text('body_ar')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 48);
            $table->string('title');
            $table->json('parameters')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('ready');
            $table->string('file_path')->nullable();
            $table->foreignUuid('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 48)->index();
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('subject');
            $table->text('message');
            $table->string('status', 16)->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['contact_messages', 'audit_logs', 'reports', 'notifications', 'announcements', 'training_needs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
