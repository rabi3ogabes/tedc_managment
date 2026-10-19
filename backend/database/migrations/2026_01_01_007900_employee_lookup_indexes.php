<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** With a whole school system's staff loaded (tens of thousands of employees) the lists, school counts and filters need their indexes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->index('school_id', 'employees_school_id_index');
            $table->index('job_title_id', 'employees_job_title_id_index');
            $table->index('department_id', 'employees_department_id_index');
            $table->index(['school_id', 'status'], 'employees_school_status_index');
            $table->index('status', 'employees_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (['employees_school_id_index', 'employees_job_title_id_index', 'employees_department_id_index', 'employees_school_status_index', 'employees_status_index'] as $i) {
                $table->dropIndex($i);
            }
        });
    }
};
