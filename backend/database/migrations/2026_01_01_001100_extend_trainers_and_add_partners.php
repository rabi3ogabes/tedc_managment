<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trainer management by source (center, school, ministry, partner, external, international)
 * and the partner organizations (Qatar Foundation, Ministry of Public Health ...) they come from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('type', 24)->default('institution')->index(); // ministry, foundation, university, health, private, ngo, institution
            $table->string('country', 64)->default('Qatar');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::table('trainers', function (Blueprint $table) {
            $table->string('source', 16)->default('center')->index(); // center, school, ministry, partner, external, international
            $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('employee_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignUuid('partner_id')->nullable()->constrained('partner_organizations')->nullOnDelete();
            $table->string('country', 64)->nullable();
            $table->string('city', 64)->nullable();
            $table->json('languages')->nullable();
            $table->decimal('experience_years', 4, 1)->default(0);
            $table->decimal('hourly_rate', 9, 2)->nullable();
            $table->string('currency', 3)->default('QAR');
            $table->text('notes')->nullable();
        });

        DB::table('trainers')->where('is_external', true)->update(['source' => 'external']);
    }

    public function down(): void
    {
        Schema::table('trainers', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['partner_id']);
            $table->dropIndex(['source']);
            $table->dropUnique(['employee_id']);
            $table->dropColumn(['source', 'school_id', 'employee_id', 'partner_id', 'country', 'city', 'languages', 'experience_years', 'hourly_rate', 'currency', 'notes']);
        });
        Schema::dropIfExists('partner_organizations');
    }
};
