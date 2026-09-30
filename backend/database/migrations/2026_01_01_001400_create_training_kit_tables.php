<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training Kit Studio (الحقيبة التدريبية): kits with their files, editable slide decks, versions,
 * anchored review comments, QA review rounds, activity and AI generation log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_kits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('program_categories')->nullOnDelete();
            // draft, in_development, in_review, changes_requested, approved, published, archived
            $table->string('status', 24)->default('draft')->index();
            $table->string('audience')->nullable();
            $table->decimal('duration_hours', 5, 1)->default(0);
            $table->json('objectives')->nullable();
            $table->json('tags')->nullable();
            $table->string('cover_path')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->unsignedSmallInteger('review_round')->default(0);
            $table->foreignUuid('owner_id')->constrained('users');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('kit_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kit_id')->constrained('training_kits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16); // developer, qa, reviewer, viewer
            $table->timestamps();
            $table->unique(['kit_id', 'user_id']);
        });

        Schema::create('kit_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kit_id')->constrained('training_kits')->cascadeOnDelete();
            $table->string('name');
            $table->string('original_name')->nullable();
            $table->string('kind', 16);                       // presentation, document, pdf, image, video, other
            $table->string('category', 24)->default('other'); // presentation, trainer_guide, handout, assessment, activity, media, other
            $table->string('source', 16)->default('upload');  // upload, created, generated
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('storage_path')->nullable();
            $table->json('content')->nullable();              // editable slide deck model
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kit_file_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('file_id')->constrained('kit_files')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('storage_path')->nullable();
            $table->json('snapshot')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('source', 16)->default('manual'); // upload, autosave, manual, restore, export, submit
            $table->string('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['file_id', 'version']);
        });

        Schema::create('kit_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kit_id')->constrained('training_kits')->cascadeOnDelete();
            $table->foreignUuid('file_id')->nullable()->constrained('kit_files')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('mime', 64);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('storage_path');
            $table->text('prompt')->nullable();
            $table->string('source', 16)->default('upload'); // upload, generated, imported
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('meta')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('kit_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kit_id')->constrained('training_kits')->cascadeOnDelete();
            $table->foreignUuid('file_id')->nullable()->constrained('kit_files')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable()->index();
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body');
            $table->json('anchor')->nullable(); // {type: slide|page|text|time|file, slide_id, element_id, x, y, page, quote, at}
            $table->unsignedInteger('file_version')->nullable();
            $table->string('category', 16)->default('content');  // content, design, language, accuracy, alignment, accessibility, other
            $table->string('severity', 12)->default('minor');    // info, minor, major, critical
            $table->string('status', 12)->default('open');       // open, addressed, resolved
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedSmallInteger('review_round')->default(0);
            $table->json('mentions')->nullable();
            $table->timestamps();
            $table->index(['file_id', 'status']);
            $table->index(['kit_id', 'status']);
        });

        Schema::create('kit_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kit_id')->constrained('training_kits')->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->string('status', 20)->default('in_progress'); // in_progress, changes_requested, approved
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
            $table->unique(['kit_id', 'round']);
        });

        Schema::create('kit_activity', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kit_id')->constrained('training_kits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('subject_type', 24)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('kit_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kit_id')->nullable()->constrained('training_kits')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);          // deck, image, storyboard, suggestions, analysis
            $table->text('prompt')->nullable();
            $table->json('params')->nullable();
            $table->json('result')->nullable();
            $table->string('provider', 24)->default('fallback'); // anthropic, openai, fallback
            $table->string('status', 12)->default('done');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['kit_generations', 'kit_activity', 'kit_reviews', 'kit_comments', 'kit_assets', 'kit_file_versions', 'kit_files', 'kit_members', 'training_kits'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
