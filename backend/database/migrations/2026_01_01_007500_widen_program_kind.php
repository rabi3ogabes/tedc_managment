<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLC workshops are programmes of kind `plc_workshop` (12 characters), but `programs.kind` was created as an
 * 8-character column for `main` | `sub`. SQLite ignores the length; PostgreSQL refuses the row, so every PLC
 * workshop failed to be created in production and in the PostgreSQL CI job. Widen the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('kind', 24)->default('main')->change();
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('kind', 8)->default('main')->change();
        });
    }
};
