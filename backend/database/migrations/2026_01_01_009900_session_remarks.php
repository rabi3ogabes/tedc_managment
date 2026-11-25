<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «ملاحظات الجلسة»: what the people who run a session (its trainer, the supervisor of its group, the centre) write about
 * it — how it went, what the room lacked, what to follow up. One remark per person per session, which they rewrite or
 * remove. A comment about one trainee is kept on that trainee's attendance record (`attendance.notes`), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_remarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->unique(['program_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_remarks');
    }
};
