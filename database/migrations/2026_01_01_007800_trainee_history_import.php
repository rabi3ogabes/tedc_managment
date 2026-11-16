<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the Ministry's trainee extract needs (employees with the courses they attended):
 *  - a blind index of the national ID (the ID itself is encrypted, so it cannot be searched or de-duplicated by value);
 *  - the HR fields the extract carries (job class, employment class, kind of employee, qualification as written, certificate, sub-unit);
 *  - every employee number a person has had (re-hired staff get a new number each time);
 *  - where a training record came from (source system, a hash that makes a re-import harmless, the course's track, type and job reference).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('national_id_hash', 64)->nullable()->unique()->after('national_id');   // HMAC-SHA256 of the ID, keyed with the app key
            $table->string('education_level', 60)->nullable()->after('qualification');             // as the Ministry writes it («جامعي»، «ماجستير»)
            $table->string('certificate_name', 191)->nullable()->after('education_level');
            $table->string('job_class', 80)->nullable()->after('job_title_id');                    // أكاديمية بالمدارس / ادارية بالمدارس / قيادية …
            $table->string('employment_class', 80)->nullable()->after('job_class');                // دائم / تعاقد / استقالة …
            $table->string('employee_kind', 40)->nullable()->after('employment_class');           // موظف / عامل
            $table->unsignedSmallInteger('employment_seq')->nullable()->after('employee_kind');   // 0 = first employment, 1 = re-hired …
            $table->string('sub_unit', 191)->nullable()->after('department_id');                   // the unit under the school, as recorded
            $table->string('source_system', 60)->nullable()->after('status')->index();
            $table->timestamp('imported_at')->nullable()->after('source_system');
        });

        Schema::create('employee_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->string('employee_no', 40)->unique();
            $table->unsignedSmallInteger('seq')->nullable();
            $table->date('appointed_on')->nullable();
            $table->string('employment_class', 80)->nullable();
            $table->string('org_name', 191)->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('source_system', 60)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'is_current']);
        });

        Schema::table('pd_activities', function (Blueprint $table) {
            $table->string('source_system', 60)->nullable()->after('status')->index();
            $table->string('row_hash', 40)->nullable()->unique()->after('source_system');         // one training record, once, however often the file is loaded
            $table->string('source_empno', 40)->nullable()->after('row_hash');                     // the employee number the record was filed under
            $table->string('course_type', 80)->nullable()->after('source_empno');                  // دورة
            $table->string('track', 191)->nullable()->after('course_type');                        // برامج تخصصية / معايير المناهج …
            $table->string('external_ref', 40)->nullable()->after('track')->index();               // the course's job number (PROG_JOBNO)
            $table->string('external_ref_desc', 191)->nullable()->after('external_ref');
            $table->index(['employee_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::table('pd_activities', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'starts_on']);
            $table->dropUnique(['row_hash']);
            $table->dropColumn(['source_system', 'row_hash', 'source_empno', 'course_type', 'track', 'external_ref', 'external_ref_desc']);
        });
        Schema::dropIfExists('employee_numbers');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['national_id_hash']);
            $table->dropColumn(['national_id_hash', 'education_level', 'certificate_name', 'job_class', 'employment_class', 'employee_kind', 'employment_seq', 'sub_unit', 'source_system', 'imported_at']);
        });
    }
};
