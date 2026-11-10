<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 13.4: problem reports (Saaed tickets) and the Sijil archive queue; HR sync bookkeeping on employees. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 24);                          // bug | access | data | request | other
            $table->string('priority', 8)->default('normal');        // low | normal | high | urgent
            $table->string('subject', 200);
            $table->text('description');
            $table->string('page_url', 500)->nullable();
            $table->json('context')->nullable();                     // app version, browser, role, locale — captured for the person
            $table->string('screenshot_path')->nullable();
            $table->string('saaed_ticket_no', 60)->nullable()->index();
            $table->string('saaed_status', 40)->nullable();
            $table->string('status', 12)->default('queued');         // queued | open | in_progress | resolved | closed | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('archive_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 16);                              // certificate | program_record
            $table->uuid('subject_id');
            $table->string('sijil_ref', 120)->nullable();
            $table->string('status', 10)->default('pending');        // pending | archived | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'subject_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_items');
        Schema::dropIfExists('support_tickets');
    }
};
