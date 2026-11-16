<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 13.3: Microsoft Teams meetings, attendance reports, group teams and Forms links. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams_meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->unique()->constrained('program_sessions')->cascadeOnDelete();
            $table->string('meeting_id', 255);
            $table->text('join_url');
            $table->string('organizer_upn', 190)->nullable();
            $table->string('kind', 12)->default('meeting');            // meeting | live_event
            $table->string('status', 10)->default('scheduled');        // scheduled | cancelled | ended
            $table->string('lobby', 20)->nullable();
            $table->text('recording_url')->nullable();
            $table->timestamp('attendance_synced_at')->nullable();
            $table->unsignedSmallInteger('attendance_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('teams_teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->unique()->constrained('training_groups')->cascadeOnDelete();
            $table->string('team_id', 80);
            $table->string('channel_id', 190)->nullable();
            $table->string('drive_id', 190)->nullable();               // the channel's SharePoint folder
            $table->string('folder_item_id', 190)->nullable();
            $table->text('web_url')->nullable();
            $table->timestamp('members_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('teams_attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('program_sessions')->cascadeOnDelete();
            $table->string('email', 190)->nullable();
            $table->string('display_name', 190)->nullable();
            $table->string('role', 20)->nullable();
            $table->unsignedInteger('total_seconds')->default(0);
            $table->json('intervals')->nullable();
            $table->foreignUuid('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('registration_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->decimal('percent', 5, 1)->default(0);
            $table->boolean('applied')->default(false);
            $table->timestamps();
            $table->index(['session_id', 'email']);
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->string('forms_url', 500)->nullable();              // an Office 365 Forms quiz used as the source
        });
    }

    public function down(): void
    {
        Schema::table('assessments', fn (Blueprint $t) => $t->dropColumn('forms_url'));
        foreach (['teams_attendance_records', 'teams_teams', 'teams_meetings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
