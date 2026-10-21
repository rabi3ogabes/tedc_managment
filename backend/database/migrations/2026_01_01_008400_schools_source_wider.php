<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** `moe_training_extract` (the schools the Ministry's trainee extract brings) is longer than the 16 characters the column allowed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing it again would cut the values already stored.
    }
};
