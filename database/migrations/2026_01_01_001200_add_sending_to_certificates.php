<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tracks when a certificate was e-mailed to its holder, by whom and how many times. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->index();
            $table->unsignedSmallInteger('sent_count')->default(0);
            $table->string('sent_to')->nullable();
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('send_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropForeign(['sent_by']);
            $table->dropIndex(['sent_at']);
            $table->dropColumn(['sent_at', 'sent_count', 'sent_to', 'sent_by', 'send_error']);
        });
    }
};
