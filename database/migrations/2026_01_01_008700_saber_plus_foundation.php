<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سابر+ on شاهين's own programmes, groups and sessions (docs/saber/00-gap-analysis.md): the number of training days of a group, the
 * training-kit readiness and external survey links of a programme; a group's colour, term and approval by the head of training; the
 * cancellation details of a programme or a group (reason, who asked, attachment, who and when); and a room's type and tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->unsignedSmallInteger('training_days')->nullable()->after('total_hours');   // days per group; empty = hours ÷ the training day
            $table->boolean('kit_ready')->nullable();                                          // empty = follow the linked kit's status
            $table->string('survey_link', 2048)->nullable();
            $table->string('survey_edit_link', 2048)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('cancel_requested_by', 160)->nullable();
            $table->string('cancel_attachment_path')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('training_groups', function (Blueprint $table) {
            $table->string('color', 9)->nullable();                  // #RRGGBB, shown on the calendar
            $table->string('term', 8)->nullable();                   // first | second | year; empty = from the first day's date
            $table->timestamp('validated_at')->nullable();           // «اعتماد المجموعة» by the head of training
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('validated_by_name', 160)->nullable();
            $table->string('cancel_requested_by', 160)->nullable();
            $table->string('cancel_attachment_path')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('training_rooms', function (Blueprint $table) {
            $table->string('room_type', 12)->default('training');   // training | meeting
            $table->string('tag', 60)->nullable();                  // مختبر، VIP، ورشة …
        });
    }

    public function down(): void
    {
        Schema::table('training_rooms', fn (Blueprint $t) => $t->dropColumn(['room_type', 'tag']));
        Schema::table('training_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validated_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['color', 'term', 'validated_at', 'validated_by_name', 'cancel_requested_by', 'cancel_attachment_path', 'cancelled_at']);
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['training_days', 'kit_ready', 'survey_link', 'survey_edit_link', 'cancel_reason', 'cancel_requested_by', 'cancel_attachment_path', 'cancelled_at']);
        });
    }
};
