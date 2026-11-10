<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NDS-08: the five programme types of the annual plan (licensing, empowerment, professionalisation, specialisation,
 * electives). A category carries a type, a programme may override it, and a plan item keeps the type it was planned
 * with. Items trimmed by the year's caps are kept as deferred with the reason, and a plan approved despite a broken
 * rule records the exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_categories', function (Blueprint $table) {
            $table->string('program_type', 20)->nullable()->index();
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->string('program_type', 20)->nullable()->index();
        });
        Schema::table('training_plan_items', function (Blueprint $table) {
            $table->string('program_type', 20)->nullable()->index();
            $table->string('deferred_reason', 40)->nullable();
        });
        Schema::table('training_plans', function (Blueprint $table) {
            $table->json('rule_exception')->nullable();
        });

        // Only where the category's own slug names the type; the others stay empty and show as "type missing".
        foreach (['licen' => 'licensing', 'empower' => 'empowerment', 'profession' => 'professionalisation', 'special' => 'specialisation', 'elective' => 'electives'] as $stem => $type) {
            DB::table('program_categories')->whereNull('program_type')->where('slug', 'like', $stem.'%')->update(['program_type' => $type]);
        }
    }

    public function down(): void
    {
        Schema::table('training_plans', fn (Blueprint $table) => $table->dropColumn('rule_exception'));
        Schema::table('training_plan_items', function (Blueprint $table) {
            $table->dropIndex(['program_type']);
            $table->dropColumn(['program_type', 'deferred_reason']);
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->dropIndex(['program_type']);
            $table->dropColumn('program_type');
        });
        Schema::table('program_categories', function (Blueprint $table) {
            $table->dropIndex(['program_type']);
            $table->dropColumn('program_type');
        });
    }
};
