<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The AI's reading of an error (cause, steps, files, verification), kept with the entry so the team sees it once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_logs', function (Blueprint $table) {
            $table->json('ai_diagnosis')->nullable()->after('note');
            $table->timestamp('ai_diagnosed_at')->nullable()->after('ai_diagnosis');
        });
    }

    public function down(): void
    {
        Schema::table('error_logs', function (Blueprint $table) {
            $table->dropColumn(['ai_diagnosis', 'ai_diagnosed_at']);
        });
    }
};
