<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 13.2: password policy and lockout, MFA, server-side sessions, linked identities (SSO, LDAP) and one-time exchange codes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('mfa_enabled')->default(false);
            $table->text('mfa_secret')->nullable();                    // encrypted TOTP secret
            $table->json('mfa_recovery')->nullable();                  // hashed one-time codes
            $table->timestamp('mfa_confirmed_at')->nullable();
        });

        Schema::create('password_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('hash');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id', 80)->nullable()->unique();   // the identity provider's session id (Supabase session_id)
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 12)->default('password');         // password | sso | ldap | mfa
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at');                           // absolute lifetime
            $table->timestamp('stepped_up_at')->nullable();            // last MFA re-check for privileged actions
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 24)->nullable();          // idle | expired | logout | admin | password
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('user_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 12);                            // entra | ldap
            $table->string('subject', 190);
            $table->string('upn', 190)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'subject']);
        });

        Schema::create('mfa_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 8);                               // email | sms
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('mfa_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sso_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('state', 64)->unique();
            $table->string('nonce', 64);
            $table->string('verifier', 128);                           // PKCE
            $table->string('redirect_after', 300)->nullable();
            $table->string('kind', 8)->default('state');               // state | code (one-time exchange code)
            $table->json('session')->nullable();                       // the session waiting to be exchanged
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['sso_states', 'mfa_devices', 'mfa_challenges', 'user_identities', 'auth_sessions', 'password_history'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['failed_attempts', 'locked_until', 'password_changed_at', 'mfa_enabled', 'mfa_secret', 'mfa_recovery', 'mfa_confirmed_at']));
    }
};
