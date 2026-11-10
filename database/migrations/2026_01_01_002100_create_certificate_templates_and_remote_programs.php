<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Certificate designs made by administrators: a background (an uploaded PDF page or image) with positioned
        // elements — text with {{placeholders}}, the verification QR, images and frames.
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('kind', 16)->default('trainee');          // trainee | trainer
            $table->decimal('width_mm', 6, 1)->default(297);
            $table->decimal('height_mm', 6, 1)->default(210);
            $table->string('background_path')->nullable();           // image used as the page background
            $table->string('source_pdf_path')->nullable();           // the PDF the background was made from
            $table->json('elements')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 12)->default('active');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->json('remote')->nullable();                      // remote delivery settings (platform, join window, ...)
            $table->uuid('certificate_template_id')->nullable();
            $table->uuid('trainer_certificate_template_id')->nullable();
        });

        Schema::table('program_sessions', function (Blueprint $table) {
            $table->string('mode', 12)->default('in_person');        // in_person | online
            $table->string('online_platform', 24)->nullable();
            $table->string('online_passcode', 64)->nullable();
            $table->string('recording_url')->nullable();
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->unsignedSmallInteger('join_count')->default(0);
            $table->timestamp('last_join_at')->nullable();
        });

        Schema::table('certificates', fn (Blueprint $table) => $table->uuid('template_id')->nullable());
        Schema::table('trainer_certificates', fn (Blueprint $table) => $table->uuid('template_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('trainer_certificates', fn (Blueprint $table) => $table->dropColumn('template_id'));
        Schema::table('certificates', fn (Blueprint $table) => $table->dropColumn('template_id'));
        Schema::table('attendance', fn (Blueprint $table) => $table->dropColumn(['join_count', 'last_join_at']));
        Schema::table('program_sessions', fn (Blueprint $table) => $table->dropColumn(['mode', 'online_platform', 'online_passcode', 'recording_url']));
        Schema::table('programs', fn (Blueprint $table) => $table->dropColumn(['remote', 'certificate_template_id', 'trainer_certificate_template_id']));
        Schema::dropIfExists('certificate_templates');
    }
};
