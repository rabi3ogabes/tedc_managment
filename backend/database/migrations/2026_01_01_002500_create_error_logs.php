<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Errors from the server, the website and the mobile app, grouped by cause (fingerprint) with a counter.
        Schema::create('error_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('fingerprint', 40)->index();
            $table->string('source', 12)->index();               // server | web | app
            $table->string('level', 12)->default('error');       // warning | error | critical
            $table->text('message');
            $table->string('exception', 190)->nullable();
            $table->string('location', 300)->nullable();         // file:line or the screen
            $table->longText('stack')->nullable();
            $table->string('method', 8)->nullable();
            $table->string('url', 500)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('user_email')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->json('device')->nullable();
            $table->json('context')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedInteger('users_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->string('status', 12)->default('open')->index(); // open | fixed | ignored
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->text('note')->nullable();
            $table->boolean('auto_fixed')->default(false);
            $table->unsignedSmallInteger('fix_attempts')->default(0);
            $table->timestamp('last_fix_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
