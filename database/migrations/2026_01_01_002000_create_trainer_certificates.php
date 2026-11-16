<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thank-you certificates of trainers, issued once a trainer has delivered all of their hours in a program.
        Schema::create('trainer_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('certificate_no', 40)->unique();
            $table->string('verification_code', 24)->unique();
            $table->foreignUuid('trainer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->timestamp('issued_at');
            $table->decimal('hours', 6, 2)->default(0);
            $table->string('file_path')->nullable();
            $table->string('status', 16)->default('valid');
            $table->string('revoked_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['trainer_id', 'program_id']);
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->timestamp('available_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', fn (Blueprint $table) => $table->dropColumn('available_notified_at'));
        Schema::dropIfExists('trainer_certificates');
    }
};
