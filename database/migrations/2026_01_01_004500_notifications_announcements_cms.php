<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 11: notification rules, scheduled sends, delivery tracking, preferences; announcement lifecycle, events, Ministry export; homepage CMS and public statistics. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 64)->index();
            $table->string('name', 160)->nullable();
            $table->json('audience_filter')->nullable();     // roles, job_titles, schools, school_groups, programs, categories
            $table->json('channels')->nullable();            // null = keep the event's own channels; else the allowed list
            $table->boolean('enabled')->default(true);
            $table->json('quiet_hours')->nullable();         // {days: [0-6], from: HH:MM, to: HH:MM, timezone, channels: [..]}
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->foreignUuid('program_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('program_categories')->nullOnDelete();
            $table->integer('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('scheduled_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->foreignUuid('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->json('channels')->nullable();
            $table->json('audience')->nullable();
            $table->timestamp('send_at')->index();
            $table->string('repeat', 10)->default('none');   // none | daily | weekly | monthly
            $table->date('repeat_until')->nullable();
            $table->string('status', 10)->default('scheduled'); // scheduled | sending | sent | cancelled | failed
            $table->foreignUuid('campaign_id')->nullable()->constrained('notification_campaigns')->nullOnDelete();
            $table->unsignedInteger('runs')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_group', 32);               // learning | attendance | approvals | announcements | system | sound
            $table->string('channel', 8);                    // push | email | sms | sound
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'event_group', 'channel']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('failed_reason', 300)->nullable();
            $table->string('provider_message_id', 120)->nullable()->index();
            $table->foreignUuid('campaign_id')->nullable()->index();
            $table->timestamp('not_before')->nullable();     // quiet hours / rule delay: do not send before this moment
            $table->index(['campaign_id', 'status']);
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('pin_order')->default(0);
            $table->string('status', 10)->default('draft')->index();   // draft | scheduled | published | expired | archived
            $table->json('media')->nullable();               // {images: [], video: [], audio: [], files: [], links: []}
            $table->json('event')->nullable();               // {starts_at, ends_at, venue_ar/en, online_url, registration_url, program_id, capacity, rsvp}
            $table->json('audience_filter')->nullable();
            $table->boolean('notify_push')->default(true);
            $table->boolean('notify_email')->default(false);
            $table->boolean('export_to_ministry')->default(false);
            $table->timestamp('exported_at')->nullable();
            $table->uuid('republished_from_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        DB::table('announcements')->whereNotNull('published_at')->update(['status' => 'published']);

        Schema::create('announcement_rsvps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('going');  // going | waitlisted | cancelled
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id']);
        });

        Schema::create('ministry_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('announcement_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('queued'); // queued | sent | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('page_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('page', 24)->default('home')->index();
            $table->string('type', 24);
            $table->json('config')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->string('audience', 12)->default('public');   // public | signed_in
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('page_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('page', 24)->index();
            $table->unsignedInteger('version');
            $table->json('blocks');                          // the published snapshot
            $table->string('note', 200)->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['page', 'version']);
        });

        Schema::create('public_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 48)->unique();
            $table->string('label_ar', 120);
            $table->string('label_en', 120);
            $table->string('source', 24);
            $table->string('value', 40)->nullable();         // custom_value
            $table->string('icon', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['public_stats', 'page_versions', 'page_blocks', 'ministry_exports', 'announcement_rsvps', 'user_notification_preferences', 'scheduled_notifications', 'notification_rules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
