<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Self-paced online course content of a program: modules of lessons (video, presentation, quiz, survey, article).
        Schema::table('programs', function (Blueprint $table) {
            $table->boolean('has_course')->default(false);
            $table->boolean('course_sequential')->default(true);   // lessons unlock one after the other
            $table->unsignedTinyInteger('course_completion_percent')->default(100); // share of required lessons needed for the certificate
        });

        Schema::create('course_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('course_lessons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('module_id')->constrained('course_modules')->cascadeOnDelete();
            $table->string('type', 16);                       // video | presentation | quiz | survey | article
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->longText('body_ar')->nullable();          // article text
            $table->longText('body_en')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->string('status', 12)->default('draft');   // draft | published
            $table->unsignedInteger('duration_seconds')->default(0);   // video length / estimated time
            $table->string('source', 12)->nullable();         // upload | url
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('external_url', 500)->nullable();
            $table->unsignedSmallInteger('slide_count')->default(0);
            $table->json('settings')->nullable();             // watch rules, quiz rules ...
            $table->timestamps();
            $table->index(['program_id', 'sort_order']);
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->string('type', 12)->default('single');    // single | multiple | true_false
            $table->text('text_ar');
            $table->text('text_en')->nullable();
            $table->json('options');                          // [{id, text_ar, text_en, correct}]
            $table->decimal('points', 5, 1)->default(1);
            $table->text('explanation_ar')->nullable();
            $table->text('explanation_en')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('survey_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->string('type', 12)->default('rating');    // rating | nps | choice | multiple | text
            $table->text('text_ar');
            $table->text('text_en')->nullable();
            $table->json('options')->nullable();              // [{id, text_ar, text_en}]
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('in_progress');   // in_progress | completed
            $table->decimal('percent', 5, 2)->default(0);
            $table->decimal('last_position', 9, 1)->default(0);     // seconds in a video, slide number in a presentation
            $table->decimal('furthest_position', 9, 1)->default(0);
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->json('segments')->nullable();                   // merged watched ranges [[from, to], ...]
            $table->unsignedSmallInteger('sessions')->default(0);   // times the lesson was opened
            $table->decimal('best_score', 5, 2)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['lesson_id', 'registration_id']);
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->json('answers');
            $table->decimal('score_percent', 5, 2)->default(0);
            $table->boolean('passed')->default(false);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->json('answers');
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['lesson_id', 'registration_id']);
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->decimal('course_percent', 5, 2)->default(0);
            $table->boolean('course_completed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('registrations', fn (Blueprint $t) => $t->dropColumn(['course_percent', 'course_completed']));
        foreach (['survey_responses', 'quiz_attempts', 'lesson_progress', 'survey_questions', 'quiz_questions', 'course_lessons', 'course_modules'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn(['has_course', 'course_sequential', 'course_completion_percent']));
    }
};
