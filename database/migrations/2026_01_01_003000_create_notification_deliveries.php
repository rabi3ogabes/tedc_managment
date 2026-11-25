<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every e-mail and SMS the platform sends (or skips, with the reason), one row per person and channel.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->nullable()->index();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('channel', 8);                       // email | sms
            $table->string('type', 64)->nullable();
            $table->string('status', 10)->default('queued');    // queued | sent | failed | skipped
            $table->string('reason', 300)->nullable();          // no_email, no_phone, not_configured, or the provider's error
            $table->string('to', 120)->nullable();              // masked address / number
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'channel', 'created_at']);
        });

        // Which channels each notification event uses (push already existed); the administrator changes them per event.
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->boolean('email')->default(true);
            $table->boolean('sms')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('notification_templates', fn (Blueprint $t) => $t->dropColumn(['email', 'sms']));
        Schema::dropIfExists('notification_deliveries');
    }
};
