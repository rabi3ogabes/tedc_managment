<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 06 — question banks, the assessment engine, attempts with integrity events, and interactive video. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title_ar');
            $table->string('title_en');
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 8)->default('private');   // private | program | center
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('bank_categories')->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('bank_categories')->nullOnDelete();
            $table->uuid('root_id')->nullable()->index();           // first version of this question
            $table->string('type', 20);
            $table->text('stem_ar');
            $table->text('stem_en')->nullable();
            $table->json('media')->nullable();
            $table->json('payload');
            $table->decimal('points', 6, 2)->default(1);
            $table->string('difficulty', 8)->default('medium');
            $table->json('skill_ids')->nullable();
            $table->text('explanation_ar')->nullable();
            $table->text('explanation_en')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 8)->default('active');         // draft | active | retired
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('content_hash', 40)->nullable()->index();
            $table->timestamps();
            $table->index(['bank_id', 'status']);
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->nullOnDelete();
            $table->foreignUuid('lesson_id')->nullable()->constrained('course_lessons')->nullOnDelete();
            $table->string('kind', 14)->default('quiz');            // final | quiz | diagnostic | pre_test | post_test | comprehensive | practice
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('instructions_ar')->nullable();
            $table->text('instructions_en')->nullable();
            $table->string('delivery', 10)->default('remote');      // remote | in_center | either
            $table->string('access_code_mode', 8)->default('none'); // none | static | rotating
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->timestamp('window_opens_at')->nullable();
            $table->timestamp('window_closes_at')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->unsignedSmallInteger('attempt_cooldown_hours')->default(0);
            $table->decimal('pass_percent', 5, 2)->default(60);
            $table->decimal('weight_in_course', 5, 2)->default(0);
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->string('feedback_mode', 12)->default('after_submit');   // immediate | after_submit | after_close | never
            $table->boolean('show_score')->default(true);
            $table->boolean('show_correct_answers')->default(false);
            $table->boolean('require_restudy_on_fail')->default(false);
            $table->json('proctoring')->nullable();
            $table->string('status', 10)->default('draft');         // draft | published | closed
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['program_id', 'kind']);
        });

        Schema::create('assessment_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('selection', 8)->default('fixed');       // fixed | random
            $table->foreignUuid('bank_id')->nullable()->constrained('question_banks')->nullOnDelete();
            $table->json('category_ids')->nullable();
            $table->json('difficulty_mix')->nullable();
            $table->unsignedSmallInteger('count')->nullable();
            $table->decimal('points_per_question', 6, 2)->nullable();
            $table->json('question_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('assessment_access_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->nullOnDelete();
            $table->string('code_hash', 64)->nullable();            // static codes
            $table->string('secret', 64)->nullable();               // rotating codes
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->foreignUuid('room_id')->nullable()->constrained('training_rooms')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('device', 255)->nullable();
            $table->string('delivery', 10)->default('remote');
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->decimal('auto_score', 8, 2)->default(0);
            $table->decimal('manual_score', 8, 2)->default(0);
            $table->decimal('max_score', 8, 2)->default(0);
            $table->decimal('score_percent', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->string('status', 12)->default('in_progress');   // in_progress | submitted | grading | graded | voided
            $table->json('integrity')->nullable();
            $table->foreignUuid('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->text('feedback')->nullable();
            $table->unsignedInteger('extra_minutes')->default(0);
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['assessment_id', 'registration_id', 'attempt_no']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('attempt_answer_grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attempt_id')->constrained('assessment_attempts')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->decimal('points_awarded', 6, 2)->default(0);
            $table->decimal('max_points', 6, 2)->default(0);
            $table->foreignUuid('grader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('video_interactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->decimal('at_seconds', 9, 1);
            $table->string('type', 12)->default('question');         // question | reflection | note | checkpoint
            $table->foreignUuid('question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->text('prompt_ar')->nullable();
            $table->text('prompt_en')->nullable();
            $table->boolean('required')->default(true);
            $table->boolean('blocks_progress')->default(true);
            $table->boolean('require_correct')->default(false);
            $table->boolean('allow_skip')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['lesson_id', 'at_seconds']);
        });

        Schema::create('video_interaction_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('interaction_id')->constrained('video_interactions')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->json('answer')->nullable();
            $table->boolean('correct')->nullable();
            $table->timestamp('answered_at');
            $table->timestamps();
            $table->unique(['interaction_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        foreach (['video_interaction_responses', 'video_interactions', 'attempt_answer_grades', 'assessment_attempts', 'assessment_access_codes', 'assessment_sections', 'assessments', 'questions', 'bank_categories', 'question_banks'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
