<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survey Studio v2, Gate 5: the training workflows on the engine. A submission may belong to a context (an evaluation
 * assignment), the legacy needs surveys and evaluation forms point at the engine survey that took them over, and a
 * ledger remembers every migration batch so it can be rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_submissions', function (Blueprint $table) {
            $table->uuid('context_id')->nullable()->after('respondent_pseudonym'); // the evaluation assignment this answer belongs to
            $table->index(['survey_id', 'context_id']);
        });
        Schema::table('needs_surveys', fn (Blueprint $table) => $table->uuid('survey_id')->nullable()->index());
        Schema::table('evaluation_forms', fn (Blueprint $table) => $table->uuid('survey_id')->nullable()->index());
        Schema::table('evaluation_assignments', function (Blueprint $table) {
            $table->uuid('survey_id')->nullable()->index();
            $table->uuid('submission_id')->nullable();
            $table->decimal('score', 5, 2)->nullable();
        });
        Schema::create('survey_migrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('batch', 40)->index();
            $table->string('legacy_type', 32); // needs_survey | evaluation_form
            $table->uuid('legacy_id');
            $table->foreignUuid('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->json('counts')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['legacy_type', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_migrations');
        Schema::table('evaluation_assignments', fn (Blueprint $table) => $table->dropColumn(['survey_id', 'submission_id', 'score']));
        Schema::table('evaluation_forms', fn (Blueprint $table) => $table->dropColumn('survey_id'));
        Schema::table('needs_surveys', fn (Blueprint $table) => $table->dropColumn('survey_id'));
        Schema::table('survey_submissions', function (Blueprint $table) {
            $table->dropIndex(['survey_id', 'context_id']);
            $table->dropColumn('context_id');
        });
    }
};
