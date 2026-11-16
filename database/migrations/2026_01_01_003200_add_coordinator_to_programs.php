<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The person who coordinates a program (shown on the lobby screen and to trainees); by default whoever created it.
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignUuid('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('programs')->whereNull('coordinator_id')->whereNotNull('created_by')->update(['coordinator_id' => DB::raw('created_by')]);
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coordinator_id');
        });
    }
};
