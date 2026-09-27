<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization structure: schools, departments, job titles, employees and skills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('type', 32)->index();          // government, private, community, international
            $table->string('gender', 16)->nullable();     // boys, girls, mixed
            $table->string('stage', 32)->index();         // kindergarten, primary, preparatory, secondary, multi
            $table->string('region', 64)->index();        // municipality
            $table->string('district')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('is_partner')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32)->nullable();
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestamps();
        });

        Schema::create('job_titles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('category', 32)->index();      // teaching, leadership, administrative, support
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('job_title_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('supervisor_id')->nullable()->index();
            $table->string('employee_no', 32)->unique();
            $table->text('national_id')->nullable();      // encrypted at rest (Laravel encrypted cast)
            $table->string('gender', 8)->nullable();
            $table->string('nationality', 64)->nullable();
            $table->date('hire_date')->nullable();
            $table->decimal('experience_years', 4, 1)->default(0);
            $table->string('education_stage', 32)->nullable();
            $table->string('qualification', 64)->nullable();
            $table->string('specialization')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('supervisor_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 48)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('category', 48)->index();      // pedagogy, leadership, digital, assessment, wellbeing ...
            $table->timestamps();
        });

        Schema::create('employee_skills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(1); // 1..5
            $table->string('source', 20)->default('self');     // self, training, supervisor
            $table->uuid('program_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skills');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('job_titles');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('schools');
    }
};
