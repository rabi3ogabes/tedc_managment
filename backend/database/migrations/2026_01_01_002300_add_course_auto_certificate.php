<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Issue the certificate by itself when a learner completes the online course and meets the other requirements.
        Schema::table('programs', fn (Blueprint $t) => $t->boolean('course_auto_certificate')->default(true));
    }

    public function down(): void
    {
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('course_auto_certificate'));
    }
};
