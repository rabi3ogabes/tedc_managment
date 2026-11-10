<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push notifications (Firebase Cloud Messaging): the devices registered by the mobile app and a
 * delivery log shown on the notification settings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 16)->default('android');
            $table->string('locale', 5)->default('ar');
            $table->string('app_version', 32)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'platform']);
        });

        Schema::create('push_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 64);
            $table->string('title', 255);
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('devices')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('pruned')->default(0);
            $table->string('error', 500)->nullable();
            $table->foreignUuid('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_logs');
        Schema::dropIfExists('device_tokens');
    }
};
