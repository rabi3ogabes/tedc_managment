<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLC Phase 6: an evaluation assignment may target one session or one trainer, and records the rule that
 * produced it. The dedupe rule becomes "one assignment per form, respondent and target".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_assignments', function (Blueprint $table) {
            $table->foreignUuid('program_session_id')->nullable()->after('subject_registration_id')->constrained('program_sessions')->nullOnDelete();
            $table->foreignUuid('trainer_id')->nullable()->after('program_session_id')->constrained('trainers')->nullOnDelete();
            $table->string('rule_key', 48)->nullable()->after('trainer_id')->index();
        });
        Schema::table('evaluation_assignments', function (Blueprint $table) {
            $table->dropUnique('evaluation_assignment_once');
            $table->unique(['form_id', 'group_id', 'respondent_user_id', 'subject_registration_id', 'program_session_id', 'trainer_id'], 'evaluation_assignment_once');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_assignments', function (Blueprint $table) {
            $table->dropUnique('evaluation_assignment_once');
            $table->unique(['form_id', 'group_id', 'respondent_user_id', 'subject_registration_id'], 'evaluation_assignment_once');
        });
        Schema::table('evaluation_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_session_id');
            $table->dropConstrainedForeignId('trainer_id');
            $table->dropColumn('rule_key');
        });
    }
};
