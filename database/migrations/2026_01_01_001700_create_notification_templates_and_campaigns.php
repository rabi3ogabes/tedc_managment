<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification templates (editable by administrators, one switch per action), campaigns (a notification the
 * administrator sends to a group, tracked per recipient) and the program survey gate (manual / automatic opening).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 64)->unique();       // e.g. program.assigned, or custom.<slug>
            $table->boolean('is_system')->default(false);
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->boolean('enabled')->default(true);   // send this notification at all
            $table->boolean('push')->default(true);      // also push it to phones
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 64)->nullable();
            $table->string('kind', 24)->default('custom'); // survey | custom
            $table->uuid('program_id')->nullable()->index();
            $table->string('audience', 24)->default('trainees');
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('campaign_id')->nullable()->index();
            $table->timestamp('seen_at')->nullable();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->string('survey_mode', 8)->default('always'); // always | manual | auto
            $table->unsignedSmallInteger('survey_auto_hours')->default(24);
            $table->timestamp('survey_opened_at')->nullable();
            $table->timestamp('survey_closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('programs', fn (Blueprint $table) => $table->dropColumn(['survey_mode', 'survey_auto_hours', 'survey_opened_at', 'survey_closed_at']));
        Schema::table('notifications', fn (Blueprint $table) => $table->dropColumn(['campaign_id', 'seen_at']));
        Schema::dropIfExists('notification_campaigns');
        Schema::dropIfExists('notification_templates');
    }
};
