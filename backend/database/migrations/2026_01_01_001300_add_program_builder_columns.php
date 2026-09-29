<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart program creation: employee birth date (age filter), the saved target audience of a
 * program and the eligibility rules generated from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->index();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->json('audience')->nullable();               // saved target filters
            $table->string('source_type', 16)->default('manual'); // manual, needs
        });

        Schema::table('eligibility_rules', function (Blueprint $table) {
            $table->boolean('is_generated')->default(false);    // produced from the program audience
        });
    }

    public function down(): void
    {
        Schema::table('eligibility_rules', fn (Blueprint $table) => $table->dropColumn('is_generated'));
        Schema::table('programs', fn (Blueprint $table) => $table->dropColumn(['audience', 'source_type']));
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['birth_date']);
            $table->dropColumn('birth_date');
        });
    }
};
