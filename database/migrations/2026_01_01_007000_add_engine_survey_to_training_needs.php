<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Survey Studio v2, Gate 6: training needs can be generated from an engine survey's results. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_needs', fn (Blueprint $table) => $table->uuid('engine_survey_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('training_needs', fn (Blueprint $table) => $table->dropColumn('engine_survey_id'));
    }
};
