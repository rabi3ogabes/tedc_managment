<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Survey Studio v2, Gate 7: a legal hold, the aggregates kept after the answers are purged, and when the key and the answers went. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->boolean('legal_hold')->default(false);
            $table->json('aggregates')->nullable();           // the summary and per-question statistics frozen when the answers are purged
            $table->timestamp('answers_purged_at')->nullable();
            $table->timestamp('key_deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('surveys', fn (Blueprint $table) => $table->dropColumn(['legal_hold', 'aggregates', 'answers_purged_at', 'key_deleted_at']));
    }
};
