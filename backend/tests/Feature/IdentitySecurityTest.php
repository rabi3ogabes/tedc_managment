<?php

namespace Tests\Feature;

use App\Integrations\IntegrationManager;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\MfaChallenge;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SsoState;
use App\Models\User;
use App\Models\UserIdentity;
use App\Security\AuthSessions;
use App\Security\PasswordService;
use App\Security\SecurityPolicy;
use App\Security\Sso\OidcClient;
use App\Security\TotpService;
use App\Services\SecuritySettings;
use Firebase\JWT\JWT;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class IdentitySecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::for('login', fn () => Limit::none());   // these tests sign in many times
    }

    private function login(User $user, array $extra = [], string $password = 'Secret#12345')
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', '')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password] + $extra);
    }

    private function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function policy(array $patch): void
    {
        app(SecurityPolicy::class)->update($patch);
    }

    public function test_the_totp_code_matches_the_rfc_6238_test_vector(): void
    {
        $totp = app(TotpService::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';           // "12345678901234567890"
        $this->assertSame('287082', $totp->code($secret, 59));    // 94287082 in the RFC, last six digits
        $this->assertSame('081804', $totp->code($secret, 1111111109));       // 07081804 in the RFC
        $this->assertTrue($totp->verify($secret, '287082', 59));
        $this->assertTrue($totp->verify($secret, '287082', 59 + 30));    // one step of clock drift
        $this->assertFalse($totp->verify($secret, '287082', 59 + 120));
        $this->assertFalse($totp->verify($secret, 'abcdef', 59));
        $this->assertSame(32, strlen($totp->newSecret()));
    }

    public function test_the_password_policy_checks_length_classes_history_and_expiry(): void
    {
        $svc = app(PasswordService::class);
        $user = $this->makeUser();
        $this->policy(['password' => ['min_length' => 12, 'symbol' => true, 'history' => 3]]);

        $this->assertEqualsCanonicalizing(['min_length', 'upper', 'digit', 'symbol'], $svc->violations('shortlow'));
        $this->assertSame([], $svc->violations('Longer-Passphrase-42!'));

        $svc->set($user, 'First-Passphrase-42!');
        $this->assertContains('reused', $svc->violations('First-Passphrase-42!', $user->fresh()));
        $svc->set($user->fresh(), 'Second-Passphrase-42!');
        $this->assertContains('reused', $svc->violations('First-Passphrase-42!', $user->fresh()));   // still inside the last three

        $this->policy(['password' => ['expiry_days' => 30]]);
        $this->assertFalse($svc->expired($user->fresh()));
        $user->forceFill(['password_changed_at' => now()->subDays(31)])->save();
        $this->assertTrue($svc->expired($user->fresh()));
        $this->assertTrue($this->login($user, [], 'Second-Passphrase-42!')->assertOk()->json('password_expired'));

        // The change endpoint applies the policy, needs the current password and ends other sessions.
        $token = $this->login($user->fresh(), [], 'Second-Passphrase-42!')->json('access_token');
        $other = $this->login($user->fresh(), [], 'Second-Passphrase-42!')->json('access_token');
        $this->bearer($token)->postJson('/api/v1/me/password', ['current_password' => 'nope', 'password' => 'Third-Passphrase-42!', 'password_confirmation' => 'Third-Passphrase-42!'])->assertStatus(422);
        $this->bearer($token)->postJson('/api/v1/me/password', ['current_password' => 'Second-Passphrase-42!', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->bearer($token)->postJson('/api/v1/me/password', ['current_password' => 'Second-Passphrase-42!', 'password' => 'Third-Passphrase-42!', 'password_confirmation' => 'Third-Passphrase-42!'])->assertOk();
        $this->bearer($other)->getJson('/api/v1/auth/me')->assertUnauthorized();                // the other device is signed out
        $this->bearer($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertFalse($this->login($user->fresh(), [], 'Third-Passphrase-42!')->json('password_expired'));
    }

    public function test_an_account_locks_after_too_many_wrong_passwords_and_an_administrator_can_unlock_it(): void
    {
        $this->policy(['lockout' => ['max_attempts' => 3, 'minutes' => 15]]);
        $user = $this->makeUser();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        for ($i = 0; $i < 3; $i++) {
            $this->login($user, [], 'wrong')->assertStatus(422);
        }
        $this->assertNotNull($user->fresh()->locked_until);
        $this->login($user)->assertStatus(423);                            // even the right password is refused while locked
        $this->assertSame(1, AuditLog::where('action', 'account_locked')->where('auditable_id', $user->id)->count());
        $this->assertSame(1, AppNotification::where('user_id', $user->id)->where('type', 'security.locked')->count());

        $this->app['auth']->forgetGuards();
        $this->asUser($admin)->postJson("/api/v1/admin/users/{$user->id}/unlock")->assertOk();
        $this->login($user)->assertOk();
        $this->assertSame(0, $user->fresh()->failed_attempts);

        // The lock also ends by itself.
        for ($i = 0; $i < 3; $i++) {
            $this->login($user, [], 'wrong');
        }
        $this->login($user)->assertStatus(423);
        $this->travel(16)->minutes();
        $this->login($user)->assertOk();
        // A successful sign-in clears the count.
        $this->login($user, [], 'wrong');
        $this->login($user)->assertOk();
        $this->assertSame(0, $user->fresh()->failed_attempts);
    }

    public function test_a_second_factor_is_asked_for_roles_that_require_it(): void
    {
        $this->policy(['mfa' => ['enforce_roles' => [Role::CENTER_ADMIN], 'methods' => ['totp', 'email'], 'remember_days' => 30]]);
        $totp = app(TotpService::class);
        $user = $this->makeUser(Role::CENTER_ADMIN, ['phone' => '55512345']);

        // Sign in: no tokens until the code is right.
        $r = $this->login($user)->assertOk();
        $this->assertTrue($r->json('mfa_required'));
        $this->assertNull($r->json('access_token'));
        $this->assertContains('email', $r->json('methods'));
        $mfaToken = $r->json('mfa_token');
        // The mfa token is not an access token.
        $this->bearer($mfaToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Enrol during sign-in, confirm with the first code, receive recovery codes and the session.
        $setup = $this->postJson('/api/v1/auth/mfa/totp/setup', ['mfa_token' => $mfaToken])->assertOk();
        $secret = $setup->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('data.otpauth_url'));
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['mfa_token' => $mfaToken, 'code' => '000000'])->assertStatus(422);
        $done = $this->postJson('/api/v1/auth/mfa/totp/confirm', ['mfa_token' => $mfaToken, 'code' => $totp->code($secret)])->assertOk();
        $this->assertNotEmpty($done->json('access_token'));
        $recovery = $done->json('recovery_codes');
        $this->assertCount(10, $recovery);
        $this->assertTrue($user->fresh()->mfa_enabled);
        $this->assertStringNotContainsString($secret, (string) $user->fresh()->mfa_secret);   // stored encrypted

        // Next sign-in: the app code works; a wrong one does not; a recovery code works once.
        $mfaToken = $this->login($user->fresh())->json('mfa_token');
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'method' => 'totp', 'code' => '123456'])->assertStatus(422);
        $ok = $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'method' => 'totp', 'code' => $totp->code($secret), 'remember_device' => true]);
        $ok->assertOk();
        $this->assertNotEmpty($ok->json('access_token'));
        $device = $ok->json('device_token');
        $this->assertNotEmpty($device);

        $mfaToken = $this->login($user->fresh())->json('mfa_token');
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'method' => 'recovery', 'code' => $recovery[0]])->assertOk();
        $mfaToken = $this->login($user->fresh())->json('mfa_token');
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'method' => 'recovery', 'code' => $recovery[0]])->assertStatus(422);   // used up
        $this->assertCount(9, $user->fresh()->mfa_recovery);

        // A remembered device skips the second factor.
        $this->assertNotEmpty($this->login($user->fresh(), ['device_token' => $device])->assertOk()->json('access_token'));
        $this->assertTrue($this->login($user->fresh(), ['device_token' => 'stolen'])->json('mfa_required'));

        // A code sent by e-mail: sent once a minute, five tries, ten minutes.
        $mfaToken = $this->login($user->fresh())->json('mfa_token');
        $this->postJson('/api/v1/auth/mfa/send', ['mfa_token' => $mfaToken, 'method' => 'email'])->assertOk();
        $this->postJson('/api/v1/auth/mfa/send', ['mfa_token' => $mfaToken, 'method' => 'email'])->assertStatus(429);
        $this->postJson('/api/v1/auth/mfa/send', ['mfa_token' => $mfaToken, 'method' => 'sms'])->assertStatus(422);       // not an allowed method
        MfaChallenge::where('user_id', $user->id)->update(['code_hash' => hash('sha256', '424242')]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'method' => 'email', 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'method' => 'email', 'code' => '424242'])->assertStatus(429);   // locked after five wrong tries

        // The role requires it, so it cannot be switched off; someone else can.
        $this->bearer($done->json('access_token'))->postJson('/api/v1/me/mfa/disable', ['code' => $totp->code($secret)])->assertStatus(422);
        $self = $this->makeUser();
        $this->bearer($this->login($self)->json('access_token'))->postJson('/api/v1/me/mfa/totp/setup')->assertOk();
    }

    public function test_sessions_end_after_idle_and_absolute_limits_and_when_an_administrator_terminates_them(): void
    {
        $this->policy(['sessions' => ['idle_minutes' => 30, 'absolute_hours' => 2]]);
        $user = $this->makeUser();
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $r = $this->login($user)->assertOk();
        $access = $r->json('access_token');
        $refresh = $r->json('refresh_token');
        $this->assertSame(1, AuthSession::where('user_id', $user->id)->count());
        $this->bearer($access)->getJson('/api/v1/auth/me')->assertOk();
        $this->bearer($access)->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.current', true);

        // Idle: no activity for thirty minutes ends it — and the refresh token cannot bring it back.
        $this->travel(31)->minutes();
        $this->bearer($access)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame('idle', AuthSession::where('user_id', $user->id)->first()->revoked_reason);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertStatus(422);
        $this->travelBack();

        // Activity keeps it alive; the absolute lifetime still ends it.
        $r = $this->login($user)->assertOk();
        $access = $r->json('access_token');
        $refresh = $r->json('refresh_token');
        for ($i = 0; $i < 3; $i++) {
            $this->travel(25)->minutes();
            Cache::flush();
            $this->bearer($access)->getJson('/api/v1/auth/me')->assertOk();
        }
        $this->travel(60)->minutes();            // past two hours in all
        $this->bearer($access)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(1, AuthSession::where('user_id', $user->id)->where('revoked_reason', 'expired')->count());
        $this->travelBack();

        // An administrator terminates a session: its tokens stop working at once.
        $r = $this->login($user)->assertOk();
        $access = $r->json('access_token');
        $refresh = $r->json('refresh_token');
        $this->bearer($access)->getJson('/api/v1/auth/me')->assertOk();
        $sid = AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->first()->id;
        $this->asUser($admin)->getJson('/api/v1/admin/auth-sessions?q='.urlencode($user->email))->assertOk()->assertJsonPath('data.0.id', $sid);
        $this->asUser($admin)->deleteJson("/api/v1/admin/auth-sessions/{$sid}")->assertOk();
        $this->bearer($access)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertStatus(422);
        $this->assertSame(1, AuditLog::where('action', 'session_terminated')->count());
        $this->asUser($user)->getJson('/api/v1/admin/auth-sessions')->assertForbidden();

        // Signing out ends it; "sign out everywhere" ends the rest.
        $a = $this->login($user)->json('access_token');
        $b = $this->login($user)->json('access_token');
        $this->bearer($a)->postJson('/api/v1/auth/logout')->assertOk();
        $this->bearer($a)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->bearer($b)->getJson('/api/v1/auth/me')->assertOk();
        $this->asUser($admin)->postJson("/api/v1/admin/users/{$user->id}/sessions/terminate")->assertOk()->assertJsonPath('data.ended', 1);
        $this->bearer($b)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertNotNull(app(AuthSessions::class));
    }

    public function test_privileged_actions_ask_for_the_second_factor_again(): void
    {
        $this->policy(['mfa' => ['enforce_roles' => [Role::CENTER_ADMIN], 'methods' => ['totp'], 'remember_days' => 0]]);
        app(SecuritySettings::class)->update(['idle_lock_enabled' => false]);   // the idle lock is a different layer
        $totp = app(TotpService::class);
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $mfa = $this->login($admin)->json('mfa_token');
        $secret = $this->postJson('/api/v1/auth/mfa/totp/setup', ['mfa_token' => $mfa])->json('data.secret');
        $access = $this->postJson('/api/v1/auth/mfa/totp/confirm', ['mfa_token' => $mfa, 'code' => $totp->code($secret)])->json('access_token');

        $body = ['password' => ['min_length' => 14]];
        $this->bearer($access)->putJson('/api/v1/admin/security-policy', $body)->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->bearer($access)->postJson('/api/v1/auth/step-up', ['code' => '111111'])->assertStatus(422);
        $this->bearer($access)->postJson('/api/v1/auth/step-up', ['code' => $totp->code($secret)])->assertOk();
        $this->bearer($access)->putJson('/api/v1/admin/security-policy', $body)->assertOk()->assertJsonPath('data.password.min_length', 14);
        $this->assertSame(1, AuditLog::where('action', 'security_policy_changed')->count());
        $this->bearer($access)->putJson('/api/v1/admin/security-policy', ['sessions' => ['idle_minutes' => 1]])->assertStatus(422);   // out of range is refused, not clamped silently

        $this->travel(11)->minutes();                                                                              // the confirmation lapses
        $this->bearer($access)->putJson('/api/v1/admin/security-policy', $body)->assertStatus(403);
    }

    // ---- single sign-on ----------------------------------------------------------------------------

    /** A fake Entra tenant: discovery, keys and a token endpoint that signs whatever claims the test wants. @return array<string, mixed> */
    private function idp(array $settings = []): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $jwks = ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'k1', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]];
        $tenant = 'tenant-1';
        $state = new \stdClass;
        $state->claims = [];
        $state->nonce = null;
        Cache::flush();
        app(IntegrationManager::class)->update('entra', ['driver' => 'oidc', 'enabled' => true, 'settings' => $settings + ['tenant_id' => $tenant, 'client_id' => 'client-1', 'client_secret' => 's3cret', 'redirect_uri' => url('/api/v1/auth/sso/callback'), 'jit' => true]]);
        Http::fake([
            "login.microsoftonline.com/{$tenant}/v2.0/.well-known/openid-configuration" => Http::response(['issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0', 'authorization_endpoint' => 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/authorize', 'token_endpoint' => 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token', 'jwks_uri' => 'https://login.microsoftonline.com/tenant-1/discovery/keys', 'end_session_endpoint' => 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/logout']),
            'login.microsoftonline.com/tenant-1/discovery/keys' => Http::response($jwks),
            'login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => function ($request) use ($private, $state) {
                $c = $state->claims + ['iss' => 'https://login.microsoftonline.com/tenant-1/v2.0', 'aud' => 'client-1', 'tid' => 'tenant-1', 'iat' => time(), 'exp' => time() + 3600, 'nonce' => $state->nonce];

                return Http::response(['id_token' => JWT::encode($c, $private, 'RS256', 'k1'), 'access_token' => 'x']);
            },
        ]);

        return ['state' => $state, 'private' => $private];
    }

    /** Runs start → provider → callback and returns the redirect Location. */
    private function ssoRound(object $state, array $claims, ?string $redirect = null): string
    {
        $start = $this->withHeader('Authorization', '')->getJson('/api/v1/auth/sso/start'.($redirect ? '?redirect='.urlencode($redirect) : ''))->assertOk();
        parse_str(parse_url($start->json('data.url'), PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame('client-1', $q['client_id']);
        $nonce = $q['nonce'];
        $state->claims = $claims;
        $state->nonce = $nonce;

        return $this->get('/api/v1/auth/sso/callback?code=abc&state='.$q['state'])->assertRedirect()->headers->get('Location');
    }

    public function test_entra_sign_in_provisions_the_person_maps_groups_and_exchanges_a_one_time_code(): void
    {
        $school = $this->makeSchool();
        $idp = $this->idp(['group_map' => json_encode([['group' => 'GROUP-TRAINERS', 'role' => 'trainer'], ['group' => 'GROUP-SCHOOL', 'role' => 'school_admin', 'scope_type' => 'school', 'scope_id' => $school->id], ['group' => 'GROUP-ROOT', 'role' => 'super_admin']])]);
        $this->assertTrue($this->withHeader('Authorization', '')->getJson('/api/v1/auth/options')->json('data.sso'));

        $loc = $this->ssoRound($idp['state'], ['sub' => 'abc', 'oid' => 'oid-1', 'preferred_username' => 'Sara@Moe.Gov.QA', 'name' => 'Sara Ali', 'groups' => ['GROUP-TRAINERS', 'group-school', 'GROUP-ROOT']]);
        $this->assertStringContainsString(rtrim((string) config('tedc.web_url'), '/').'/sso/callback?code=', $loc);
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);

        $user = User::where('email', 'sara@moe.gov.qa')->firstOrFail();                    // created just in time
        $this->assertSame('Sara Ali', $user->name);
        $this->assertEqualsCanonicalizing([Role::EMPLOYEE, Role::TRAINER, Role::SCHOOL_ADMIN], $user->roles()->pluck('slug')->all());   // never super_admin from a directory group
        $this->assertSame($school->id, RoleUser::where('user_id', $user->id)->where('scope_type', 'school')->value('scope_id'));
        $this->assertSame('oid-1', UserIdentity::where('user_id', $user->id)->value('subject'));

        $session = $this->withHeader('Authorization', '')->postJson('/api/v1/auth/sso/exchange', ['code' => $q['code']])->assertOk();
        $this->assertSame($user->id, $session->json('user.id'));
        $this->bearer($session->json('access_token'))->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame('sso', AuthSession::where('user_id', $user->id)->value('method'));
        $this->withHeader('Authorization', '')->postJson('/api/v1/auth/sso/exchange', ['code' => $q['code']])->assertStatus(400);   // one time only

        // A second sign-in finds the same person (by identity) even if their e-mail changed.
        $loc = $this->ssoRound($idp['state'], ['sub' => 'abc', 'oid' => 'oid-1', 'preferred_username' => 'sara.new@moe.gov.qa', 'name' => 'Sara Ali', 'groups' => []]);
        $this->assertStringContainsString('code=', $loc);
        $this->assertSame(1, User::where('name', 'Sara Ali')->count());

        // The logout address ends the provider's session too.
        $out = $this->bearer($session->json('access_token'))->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertStringContainsString('oauth2/v2.0/logout', (string) $out->json('data.sso_logout_url'));
    }

    public function test_entra_tokens_that_are_forged_stale_or_meant_for_someone_else_are_refused(): void
    {
        $idp = $this->idp();
        $fail = fn (array $claims) => $this->assertStringContainsString('sso_error=1', $this->ssoRound($idp['state'], $claims + ['sub' => 'z', 'oid' => 'oid-9', 'preferred_username' => 'x@moe.gov.qa']));
        $fail(['aud' => 'some-other-app']);
        $fail(['iss' => 'https://evil.example/v2.0']);
        $fail(['exp' => time() - 3600]);
        $fail(['iat' => time() + 7200, 'nbf' => time() + 7200]);
        $this->assertSame(0, User::where('email', 'x@moe.gov.qa')->count());

        // A wrong nonce (a replayed or injected token) is refused.
        $start = $this->withHeader('Authorization', '')->getJson('/api/v1/auth/sso/start');
        parse_str(parse_url($start->json('data.url'), PHP_URL_QUERY), $q);
        $idp['state']->claims = ['sub' => 'z', 'oid' => 'oid-9', 'preferred_username' => 'x@moe.gov.qa'];
        $idp['state']->nonce = 'not-the-nonce';
        $this->assertStringContainsString('sso_error=1', $this->get('/api/v1/auth/sso/callback?code=abc&state='.$q['state'])->headers->get('Location'));
        $this->get('/api/v1/auth/sso/callback?code=abc&state='.$q['state'])->assertRedirect();       // the state was used up; still refused
        $this->assertStringContainsString('sso_error=1', $this->get('/api/v1/auth/sso/callback?code=abc&state=unknown')->headers->get('Location'));

        // A token signed with another key (a forgery) is refused.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPrivate);
        $start = $this->withHeader('Authorization', '')->getJson('/api/v1/auth/sso/start');
        parse_str(parse_url($start->json('data.url'), PHP_URL_QUERY), $q);
        Http::fake(['login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response(['id_token' => JWT::encode(['iss' => 'https://login.microsoftonline.com/tenant-1/v2.0', 'aud' => 'client-1', 'tid' => 'tenant-1', 'iat' => time(), 'exp' => time() + 3600, 'nonce' => $q['nonce'], 'sub' => 'f', 'preferred_username' => 'forged@moe.gov.qa'], $otherPrivate, 'RS256', 'k1')])]);
        $this->assertStringContainsString('sso_error=1', $this->get('/api/v1/auth/sso/callback?code=abc&state='.$q['state'])->headers->get('Location'));
        $this->assertSame(0, User::where('email', 'forged@moe.gov.qa')->count());
        $this->assertNotNull(OidcClient::challenge('x'));
    }

    public function test_domains_jit_and_the_break_glass_rule_for_local_passwords(): void
    {
        $idp = $this->idp(['allowed_domains' => 'moe.gov.qa', 'jit' => false, 'break_glass' => false]);
        $known = $this->makeUser(Role::EMPLOYEE, ['email' => 'known@moe.gov.qa']);

        // Not provisioned and JIT is off; a foreign domain is not allowed.
        $this->assertStringContainsString('sso_error=1', $this->ssoRound($idp['state'], ['sub' => 'n', 'oid' => 'n1', 'preferred_username' => 'new@moe.gov.qa']));
        $this->assertStringContainsString('sso_error=1', $this->ssoRound($idp['state'], ['sub' => 'o', 'oid' => 'o1', 'preferred_username' => 'someone@gmail.com']));
        $this->assertStringContainsString('code=', $this->ssoRound($idp['state'], ['sub' => 'k', 'oid' => 'k1', 'preferred_username' => 'known@moe.gov.qa']));      // an existing account links by e-mail

        // With break-glass off, ministry accounts cannot use a local password; the super administrator and outside accounts can.
        $this->login($known)->assertStatus(403);
        $this->assertSame(0, AuthSession::where('user_id', $known->id)->whereNull('revoked_at')->count());
        $this->login($this->makeUser(Role::SUPER_ADMIN, ['email' => 'root@moe.gov.qa']))->assertOk();
        $this->login($this->makeUser(Role::EMPLOYEE, ['email' => 'partner@company.example']))->assertOk();
        app(IntegrationManager::class)->update('entra', ['settings' => ['break_glass' => true]]);
        $this->login($known)->assertOk();

        // The redirect after sign-in may only be the web app or the mobile app's scheme.
        $start = $this->withHeader('Authorization', '')->getJson('/api/v1/auth/sso/start?redirect='.urlencode('https://evil.example/steal'))->assertOk();
        $this->assertNull(SsoState::where('state', $start->json('data.state'))->value('redirect_after'));
        $start = $this->withHeader('Authorization', '')->getJson('/api/v1/auth/sso/start?redirect='.urlencode('tedc://sso'))->assertOk();
        $this->assertSame('tedc://sso', SsoState::where('state', $start->json('data.state'))->value('redirect_after'));
    }

    public function test_directory_sign_in_checks_the_password_creates_the_account_and_maps_groups(): void
    {
        $hub = app(IntegrationManager::class);
        $this->withHeader('Authorization', '')->postJson('/api/v1/auth/ldap/login', ['username' => 'a', 'password' => 'b'])->assertStatus(404);   // not set up
        $hub->update('ldap', ['driver' => 'fake', 'enabled' => true, 'settings' => ['group_map' => json_encode([['group' => 'CN=Trainers', 'role' => 'trainer']])]]);
        $hub->get('ldap')->forceFill(['config' => null])->save();
        $i = $hub->get('ldap');
        $i->putSettings(['fake_users' => [['username' => 'hamad', 'password' => 'Dir#Pass1', 'email' => 'hamad@moe.gov.qa', 'name' => 'Hamad', 'employee_no' => 'E-77', 'groups' => ['CN=Trainers']]], 'group_map' => [['group' => 'CN=Trainers', 'role' => 'trainer']]]);
        $i->save();
        $emp = $this->makeEmployee(['employee_no' => 'E-77', 'user_id' => $this->makeUser(Role::EMPLOYEE, ['email' => 'hamad@moe.gov.qa'])->id]);

        $call = fn (string $u, string $p) => $this->withHeader('Authorization', '')->postJson('/api/v1/auth/ldap/login', ['username' => $u, 'password' => $p]);
        $call('hamad', 'wrong')->assertStatus(401);
        $call('hamad', '')->assertStatus(422);                            // an empty password is never an anonymous bind
        $call('nobody', 'Dir#Pass1')->assertStatus(401);
        $ok = $call('hamad', 'Dir#Pass1')->assertOk();
        $this->assertSame($emp->user_id, $ok->json('user.id'));            // linked to the existing account by e-mail
        $this->assertContains(Role::TRAINER, User::find($emp->user_id)->roles()->pluck('slug')->all());
        $this->assertSame('ldap', AuthSession::where('user_id', $emp->user_id)->value('method'));
        $this->assertSame(1, UserIdentity::where('provider', 'ldap')->count());
        $this->bearer($ok->json('access_token'))->getJson('/api/v1/auth/me')->assertOk();
    }
}
