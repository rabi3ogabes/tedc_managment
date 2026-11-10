<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A programme's evaluation instruments (the trainee survey, the trainer's self-reflection, the pre-test and the
 * post-test), generated from the training kits chosen for it, and the mark that its registration-open announcement
 * (to the trainers and the schools' system administrators) has been sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_instruments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);                                   // trainee_survey | trainer_reflection | pre_test | post_test
            $table->boolean('enabled')->default(true);
            $table->foreignUuid('form_id')->nullable()->constrained('evaluation_forms')->nullOnDelete();
            $table->foreignUuid('assessment_id')->nullable()->constrained('assessments')->nullOnDelete();
            $table->foreignUuid('source_kit_id')->nullable()->constrained('training_kits')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['program_id', 'kind']);
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->timestamp('registration_announced_at')->nullable()->after('survey_closed_at');
        });
        // Programmes that are already open were announced in their time: nothing is sent for them now.
        DB::table('programs')->where('status', 'registration_open')->update(['registration_announced_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('programs', fn (Blueprint $table) => $table->dropColumn('registration_announced_at'));
        Schema::dropIfExists('program_instruments');
    }
};
