<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سابر+ phase 2 (docs/saber/02-scheduling-tools.md): weekly holidays with a label, «allow an exceptional event» on a holiday, requests to
 * train on a closed day (request → decision), and what a cancellation needs to be undone: the status before it and, for a group, the room
 * and trainer of each of its days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_weekly_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedTinyInteger('weekday')->unique();          // 0 = Sunday … 6 = Saturday
            $table->string('title_ar', 160)->nullable();
            $table->string('title_en', 160)->nullable();
            $table->boolean('allow_events')->default(false);          // meetings and events may still be booked
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('calendar_days', function (Blueprint $table) {
            $table->boolean('allow_events')->default(false);
        });

        Schema::create('calendar_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date')->index();
            $table->foreignUuid('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignUuid('training_group_id')->nullable()->constrained('training_groups')->nullOnDelete();
            $table->unsignedSmallInteger('day_idx')->nullable();
            $table->text('reason');
            $table->string('status', 12)->default('pending')->index();   // pending | approved | rejected
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->string('status_before_cancel', 24)->nullable();
        });
        Schema::table('training_groups', function (Blueprint $table) {
            $table->string('status_before_cancel', 24)->nullable();
            $table->json('cancel_snapshot')->nullable();                 // [{session_id, room_id, trainer_id}] to restore on reactivation
        });
    }

    public function down(): void
    {
        Schema::table('training_groups', fn (Blueprint $t) => $t->dropColumn(['status_before_cancel', 'cancel_snapshot']));
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('status_before_cancel'));
        Schema::dropIfExists('calendar_requests');
        Schema::table('calendar_days', fn (Blueprint $t) => $t->dropColumn('allow_events'));
        Schema::dropIfExists('calendar_weekly_rules');
    }
};
