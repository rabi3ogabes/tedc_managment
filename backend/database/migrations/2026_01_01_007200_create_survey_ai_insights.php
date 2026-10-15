<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Survey Studio v2, Gate 8: what the configured model produced for a survey (summaries of open text, question suggestions), kept for the people who asked. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_ai_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->uuid('version_id')->nullable();
            $table->string('question_id', 40)->nullable();      // one question, or null for all open text
            $table->string('kind', 16);                          // summary | suggestions
            $table->json('payload');
            $table->unsignedInteger('answers_count')->default(0);
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('redactions')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['survey_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_ai_insights');
    }
};
