<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Program catalogue: categories, trainers, rooms, programs, sessions,
 * target groups, eligibility rules and training materials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('icon', 48)->nullable();
            $table->string('color', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('trainers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('title_en')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('bio_en')->nullable();
            $table->text('bio_ar')->nullable();
            $table->json('specializations')->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_external')->default(false);
            $table->string('organization')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('training_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('building')->nullable();
            $table->unsignedInteger('capacity')->default(30);
            $table->json('facilities')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->foreignUuid('category_id')->nullable()->constrained('program_categories')->nullOnDelete();
            $table->string('title_en');
            $table->string('title_ar');
            $table->string('summary_en', 500)->nullable();
            $table->string('summary_ar', 500)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->json('objectives')->nullable();
            $table->string('delivery_mode', 16)->default('in_person'); // in_person, online, hybrid
            $table->string('level', 16)->default('intermediate');      // beginner, intermediate, advanced
            $table->decimal('total_hours', 6, 1)->default(0);
            $table->unsignedInteger('capacity')->default(30);
            $table->unsignedTinyInteger('min_attendance_percent')->default(80);
            $table->boolean('requires_tasks')->default(true);
            $table->boolean('requires_evaluation')->default(true);
            $table->date('start_date')->nullable()->index();
            $table->date('end_date')->nullable();
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->json('registration_modes')->nullable(); // self, school_nomination, center_nomination, bulk_import
            $table->string('status', 24)->default('draft')->index();
            $table->string('cover_path')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('program_skill', function (Blueprint $table) {
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('target_level')->default(3);
            $table->primary(['program_id', 'skill_id']);
        });

        Schema::create('program_trainer', function (Blueprint $table) {
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('trainer_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('lead');
            $table->primary(['program_id', 'trainer_id']);
        });

        Schema::create('program_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('trainer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('training_room_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description')->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->string('location_text')->nullable();
            $table->string('online_url')->nullable();
            $table->json('activities')->nullable();
            $table->string('status', 16)->default('scheduled'); // scheduled, live, completed, cancelled
            $table->string('qr_secret', 64);
            $table->timestamps();
        });

        Schema::create('target_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('school_type', 32)->nullable();
            $table->string('education_stage', 32)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('eligibility_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->string('field', 48);          // job_title, department, school_type, education_stage, experience_years, completed_program, skill_level ...
            $table->string('operator', 24);       // eq, neq, in, not_in, gt, gte, lt, lte, completed, not_completed, has_skill
            $table->json('value');
            $table->string('message_en')->nullable();
            $table->string('message_ar')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('program_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title_en');
            $table->string('title_ar');
            $table->string('type', 16);           // file, video, link, document, presentation
            $table->string('storage_path')->nullable();
            $table->string('url')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('visibility', 16)->default('participants'); // participants, trainers, public
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['materials', 'eligibility_rules', 'target_groups', 'program_sessions', 'program_trainer', 'program_skill', 'programs', 'training_rooms', 'trainers', 'program_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
