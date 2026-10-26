<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سابر+ phase 4 (docs/saber/04-data-tools.md): a trainer's national ID (encrypted, with a hash to find them and their staff record) and
 * the approval that lets supervisors pick them; the uploaded list of survey links (م، رابط الإستمارة، رابط تعديل الإستمارة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainers', function (Blueprint $table) {
            $table->text('national_id')->nullable();                     // encrypted
            $table->string('national_id_hash', 64)->nullable()->index();
            $table->timestamp('validated_at')->nullable();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        // Trainers already in use stay pickable; only those added from now on wait for approval.
        DB::table('trainers')->update(['validated_at' => now()]);

        Schema::create('saber_survey_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('serial', 32)->index();
            $table->string('survey_link', 2048)->nullable();
            $table->string('survey_edit_link', 2048)->nullable();
            $table->foreignUuid('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saber_survey_links');
        Schema::table('trainers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn(['national_id', 'national_id_hash', 'validated_at']);
        });
    }
};
