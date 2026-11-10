<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TYP-14 / TEC-13: the learning providers follow their own published APIs. A course keeps what the provider says about it (a description,
 * level, language, image, whether it is still offered); every sync is recorded with what it did; and a completion that cannot be matched to
 * a person or a registration is kept with its reason until the cause is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_courses', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('level', 20)->nullable();
            $table->string('language', 10)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('status', 12)->default('available');          // available | retired | removed (no longer in the provider's catalogue)
            $table->timestamp('last_seen_at')->nullable();
            $table->index(['provider', 'status']);
        });

        Schema::create('external_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 20);
            $table->string('status', 10)->default('running');            // running | ok | partial | failed
            $table->string('trigger', 10)->default('schedule');          // schedule | manual
            $table->foreignUuid('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('catalogue_at')->nullable();               // set when the catalogue part succeeded: where the next incremental read starts
            $table->boolean('catalogue_full')->default(false);
            $table->timestamp('completions_at')->nullable();             // set when the completions part succeeded
            $table->unsignedInteger('courses_seen')->default(0);
            $table->unsignedInteger('courses_added')->default(0);
            $table->unsignedInteger('completions_seen')->default(0);
            $table->unsignedInteger('completions_applied')->default(0);
            $table->unsignedInteger('issues_found')->default(0);
            $table->string('error', 400)->nullable();
            $table->timestamps();
            $table->index(['provider', 'started_at']);
        });

        Schema::create('external_completion_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 20);
            $table->string('external_course_id');
            $table->string('course_title')->nullable();
            $table->string('learner_ref');                               // the employee number or e-mail address the provider gave
            $table->string('reason', 24);                                // unknown_employee | not_registered
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 12)->nullable();                // fixed (it matched later) | dismissed (an administrator)
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['provider', 'external_course_id', 'learner_ref', 'reason'], 'external_completion_issues_unique');
            $table->index(['provider', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_completion_issues');
        Schema::dropIfExists('external_sync_runs');
        Schema::table('external_courses', function (Blueprint $table) {
            $table->dropIndex(['provider', 'status']);
            $table->dropColumn(['description', 'level', 'language', 'image_url', 'status', 'last_seen_at']);
        });
    }
};
