<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Teams town halls beside meetings (kind = townhall): the event's session (for its join link and attendance) and who was invited. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams_meetings', function (Blueprint $table) {
            $table->string('graph_session_id', 200)->nullable()->after('meeting_id');
            $table->string('attendees_hash', 64)->nullable()->after('lobby');
            $table->unsignedInteger('invited_count')->nullable()->after('attendees_hash');
        });
    }

    public function down(): void
    {
        Schema::table('teams_meetings', function (Blueprint $table) {
            $table->dropColumn(['graph_session_id', 'attendees_hash', 'invited_count']);
        });
    }
};
