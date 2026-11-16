<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEC-08, TEDC as an LTI tool: the platforms (other LMSs) allowed to launch TEDC, each launch with what the platform
 * sent back for grades and rosters, and the login state of a tool launch (single use, ten minutes) next to the
 * platform-side states.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lti_platforms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('version', 4)->default('1.3');              // 1.3 | 1.1
            $table->string('issuer', 255);
            $table->string('client_id', 255);
            $table->json('deployment_ids')->nullable();
            $table->string('auth_login_url', 500)->nullable();
            $table->string('auth_token_url', 500)->nullable();
            $table->string('jwks_url', 500)->nullable();
            $table->text('public_key')->nullable();
            $table->string('consumer_key', 120)->nullable()->unique();  // LTI 1.1
            $table->text('consumer_secret')->nullable();                // LTI 1.1, encrypted
            $table->string('status', 10)->default('active');           // active | disabled
            $table->json('settings')->nullable();                       // program_ids, trusted_domains, roles {learner, instructor}
            $table->timestamps();
            $table->unique(['issuer', 'client_id']);
        });

        Schema::create('lti_tool_launches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_id')->constrained('lti_platforms')->cascadeOnDelete();
            $table->string('sub', 255);
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message_type', 32);
            $table->string('context_id', 255)->nullable();
            $table->string('resource_link_id', 255)->nullable();
            $table->string('target_type', 10)->nullable();              // program | lesson
            $table->uuid('target_id')->nullable();
            $table->uuid('registration_id')->nullable();
            $table->json('claims')->nullable();                         // name, email, roles, locale: what the launch said
            $table->string('ags_lineitem', 500)->nullable();
            $table->string('ags_lineitems', 500)->nullable();
            $table->json('ags_scopes')->nullable();
            $table->string('nrps_url', 500)->nullable();
            $table->string('outcome_url', 500)->nullable();             // LTI 1.1 Basic Outcomes
            $table->string('outcome_sourcedid', 500)->nullable();
            $table->json('deep_link')->nullable();                      // return URL, data, accepted types
            $table->string('ticket', 64)->nullable()->unique();         // linking an existing account after the person signs in
            $table->timestamp('used_at')->nullable();
            $table->timestamp('last_score_at')->nullable();
            $table->string('last_score_error', 300)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'target_id']);
            $table->index(['platform_id', 'sub']);
        });

        Schema::table('lti_states', function (Blueprint $table) {
            $table->uuid('tool_id')->nullable()->change();
            $table->foreignUuid('platform_id')->nullable()->constrained('lti_platforms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lti_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('platform_id');
        });
        Schema::dropIfExists('lti_tool_launches');
        Schema::dropIfExists('lti_platforms');
    }
};
