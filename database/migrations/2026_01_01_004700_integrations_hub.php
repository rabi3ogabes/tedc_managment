<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 13.1: the integration hub (systems, logs), the outbox / webhook event bus and inbound idempotency. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->string('key', 32)->primary();
            $table->string('driver', 16)->default('fake');         // fake | http | ldap | graph …
            $table->text('config')->nullable();                     // encrypted JSON
            $table->boolean('enabled')->default(false);
            $table->string('health', 10)->default('unknown');       // ok | degraded | down | unknown
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('last_check_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);   // consecutive failures (circuit breaker)
            $table->timestamp('open_until')->nullable();            // circuit open until
            $table->timestamps();
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('integration_key', 32)->index();
            $table->string('direction', 3);                         // in | out
            $table->string('operation', 80);
            $table->string('status', 8);                            // ok | error
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('request_summary')->nullable();
            $table->json('response_summary')->nullable();
            $table->text('error')->nullable();
            $table->string('correlation_id', 40)->nullable()->index();
            $table->timestamp('created_at')->index();
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 64)->index();
            $table->json('payload');
            $table->string('correlation_id', 40)->nullable();
            $table->timestamp('occurred_at')->index();
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('url', 500);
            $table->text('secret');                                  // encrypted
            $table->json('events');                                  // ['registration.approved', …] or ['*']
            $table->boolean('enabled')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->foreignUuid('outbox_event_id')->constrained('outbox_events')->cascadeOnDelete();
            $table->string('status', 10)->default('pending');        // pending | delivered | dead
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('inbound_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source', 32);
            $table->string('idempotency_key', 120);
            $table->json('payload')->nullable();
            $table->string('result', 20)->default('processed');      // processed | ignored | failed
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        foreach (['inbound_events', 'webhook_deliveries', 'webhook_subscriptions', 'outbox_events', 'integration_logs', 'integrations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
