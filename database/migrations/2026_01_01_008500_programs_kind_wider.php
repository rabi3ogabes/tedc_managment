<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PLC workshop's programme is of kind `plc_workshop` (12 characters), longer than the 8 the column allowed: PostgreSQL refused the
 * insert (SQLite does not check lengths, so only the live database showed it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('kind', 16)->default('main')->change();
        });
    }

    public function down(): void
    {
        // Narrowing it again would cut the values already stored.
    }
};
