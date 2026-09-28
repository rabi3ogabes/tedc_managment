<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training-needs assessment surveys: built from a template, imported from Word / Excel or
 * designed in the builder, sent to a filtered target audience, and analysed into training needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('needs_surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('draft')->index(); // draft, published, closed
            $table->string('source', 16)->default('builder');         // builder, template, import
            $table->string('template_key', 48)->nullable();
            $table->json('questions');
            $table->json('audience')->nullable();
            $table->json('settings')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('needs_survey_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('needs_surveys')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['survey_id', 'user_id']);
            $table->index(['user_id', 'responded_at']);
        });

        Schema::create('needs_survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('needs_surveys')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // Profile snapshot at the time of answering, so reports can be sliced without asking again.
            $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('job_title_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('experience_years', 4, 1)->nullable();
            $table->string('specialization')->nullable();
            $table->string('nationality', 64)->nullable();
            $table->string('gender', 8)->nullable();
            $table->string('education_stage', 32)->nullable();
            $table->json('answers');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['survey_id', 'user_id']);
        });

        Schema::table('training_needs', function (Blueprint $table) {
            $table->foreignUuid('survey_id')->nullable()->after('program_id')->constrained('needs_surveys')->nullOnDelete();
            $table->unsignedTinyInteger('need_index')->nullable()->after('survey_id');
        });

        // Needs generated from a survey can cover every school at once.
        Schema::table('training_needs', function (Blueprint $table) {
            $table->uuid('school_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('training_needs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('survey_id');
            $table->dropColumn('need_index');
        });
        Schema::dropIfExists('needs_survey_responses');
        Schema::dropIfExists('needs_survey_recipients');
        Schema::dropIfExists('needs_surveys');
    }
};
