<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NFR-07: SAML 2.0 beside OpenID Connect, and single sign-on in the app. Assertion ids already used (replay protection,
 * shared by every server) and, per session, what single logout needs (NameID and session index; encrypted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_replays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('assertion_id', 190)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->text('sso_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', fn (Blueprint $table) => $table->dropColumn('sso_meta'));
        Schema::dropIfExists('sso_replays');
    }
};
