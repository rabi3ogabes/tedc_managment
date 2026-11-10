<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 17: security events, the queue towards the SIEM, and data-subject requests (Qatar Law 13 of 2016). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 24);
            $table->uuid('user_id')->nullable();
            $table->string('outcome', 12)->default('ok');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 250)->nullable();
            $table->string('request_id', 40)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['type', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('siem_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 12);                               // audit | security | integration
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 250)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['sent_at', 'created_at']);
        });

        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);                               // access | correction | erasure | objection | restriction
            $table->text('details')->nullable();
            $table->string('status', 12)->default('received');        // received | in_progress | completed | rejected
            $table->timestamp('due_at');
            $table->foreignUuid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        foreach (['data_subject_requests', 'siem_outbox', 'security_events'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
