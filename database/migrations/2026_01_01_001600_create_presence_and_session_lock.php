<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live presence ("who is online now") and the idle lock of the administration team.
 * A presence session is one continuous visit of a user on one platform: it is extended by every heartbeat and a
 * new one starts after 10 idle minutes, which also gives the usage report its sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presence_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 8);            // web | mobile
            $table->string('team', 8);                // staff | members
            $table->string('role_label', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('hits')->default(1);
            $table->unsignedInteger('idle_seconds')->default(0);
            $table->string('last_path', 191)->nullable();
            $table->string('device', 120)->nullable();
            $table->string('app_version', 24)->nullable();

            $table->index('last_seen_at');
            $table->index(['user_id', 'platform', 'last_seen_at']);
            $table->index('started_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('locked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['last_active_at', 'locked_at']));
        Schema::dropIfExists('presence_sessions');
    }
};
