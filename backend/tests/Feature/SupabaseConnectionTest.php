<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\Supabase;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Supabase projects using the new API keys (sb_publishable_ / sb_secret_) and asymmetric
 * JWT signing keys published at the JWKS endpoint.
 */
class SupabaseConnectionTest extends TestCase
{
    private const URL = 'https://demo-project.supabase.co';

    private string $privateKey = '';

    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tedc.auth.driver' => 'supabase',
            'tedc.auth.jwt_secret' => '',
            'tedc.supabase.url' => self::URL,
            'tedc.supabase.anon_key' => 'sb_publishable_test',
            'tedc.supabase.service_role_key' => 'sb_secret_test',
            'tedc.supabase.jwks_url' => self::URL.'/auth/v1/.well-known/jwks.json',
        ]);
        Cache::flush();

        // ES256 key pair, like Supabase's default asymmetric JWT signing key.
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $this->privateKey);
        $ec = openssl_pkey_get_details($key)['ec'];
        $this->jwk = [
            'kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig', 'kid' => 'test-key',
            'x' => JWT::urlsafeB64Encode($ec['x']), 'y' => JWT::urlsafeB64Encode($ec['y']),
        ];
    }

    private function supabaseToken(string $authId, string $email): string
    {
        return JWT::encode(['sub' => $authId, 'email' => $email, 'aud' => 'authenticated', 'role' => 'authenticated', 'exp' => time() + 3600], $this->privateKey, 'ES256', 'test-key');
    }

    public function test_login_through_supabase_auth_with_new_keys_and_jwks(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'admin@tedc.qa']);
        $authId = '6b2a1c9e-1111-4a3b-9c1d-000000000001';
        $token = $this->supabaseToken($authId, 'admin@tedc.qa');

        Http::fake([
            self::URL.'/auth/v1/token*' => Http::response([
                'access_token' => $token, 'refresh_token' => 'refresh-abc', 'expires_in' => 3600,
                'user' => ['id' => $authId, 'email' => 'admin@tedc.qa'],
            ]),
            self::URL.'/auth/v1/.well-known/jwks.json' => Http::response(['keys' => [$this->jwk]]),
        ]);

        $access = $this->postJson('/api/v1/auth/login', ['email' => 'admin@tedc.qa', 'password' => 'secret'])
            ->assertOk()->json('access_token');

        $this->assertSame($authId, $user->fresh()->auth_id);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$access}")->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'admin@tedc.qa');

        // Publishable key goes in `apikey` only; never as a Bearer token.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/auth/v1/token') && $r->hasHeader('apikey', 'sb_publishable_test') && ! $r->hasHeader('Authorization'));
    }

    public function test_token_signed_by_another_key_is_rejected(): void
    {
        $this->makeUser(Role::EMPLOYEE, ['email' => 'x@tedc.qa']);
        Http::fake([self::URL.'/auth/v1/.well-known/jwks.json' => Http::response(['keys' => [$this->jwk]])]);

        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($other, $otherPem);
        $forged = JWT::encode(['sub' => 'x', 'email' => 'x@tedc.qa', 'aud' => 'authenticated', 'exp' => time() + 60], $otherPem, 'ES256', 'test-key');

        $this->withHeader('Authorization', "Bearer {$forged}")->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_secret_key_is_sent_only_as_apikey_header(): void
    {
        Http::fake([
            self::URL.'/auth/v1/admin/users?*' => Http::response(['users' => []]),
            self::URL.'/auth/v1/admin/users' => Http::response(['id' => '33333333-3333-3333-3333-333333333333']),
        ]);
        $this->makeUser(Role::EMPLOYEE, ['email' => 'sync@tedc.qa']);

        $this->artisan('tedc:supabase-sync-users', ['--password' => 'Tedc@2026!'])->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->hasHeader('apikey', 'sb_secret_test') && ! $r->hasHeader('Authorization'));
    }

    public function test_tls_certificate_failure_returns_a_clear_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)'));

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@tedc.qa', 'password' => 'secret'], ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('auth.tls_error', [], 'en'));
    }

    public function test_configured_ca_bundle_is_used_for_tls_verification(): void
    {
        config(['tedc.supabase.ca_bundle' => '/etc/ssl/cacert.pem']);

        $this->assertSame('/etc/ssl/cacert.pem', Supabase::public()->getOptions()['verify']);
        $this->assertSame('/etc/ssl/cacert.pem', Supabase::admin()->getOptions()['verify']);

        // Without configuration a trusted bundle is still found, so TLS works on PHP installs without a CA store.
        config(['tedc.supabase.ca_bundle' => '']);
        $this->assertFileExists(Supabase::http()->getOptions()['verify']);
    }

    public function test_sync_can_reset_the_password_of_existing_supabase_users(): void
    {
        $id = '44444444-4444-4444-4444-444444444444';
        Http::fake([
            self::URL.'/auth/v1/admin/users?*' => Http::response(['users' => [['id' => $id, 'email' => 'exists@tedc.qa']]]),
            self::URL."/auth/v1/admin/users/{$id}" => Http::response(['id' => $id]),
        ]);
        $user = $this->makeUser(Role::EMPLOYEE, ['email' => 'exists@tedc.qa']);

        $this->artisan('tedc:supabase-sync-users', ['--password' => 'Tedc@2026!', '--email' => ['exists@tedc.qa'], '--reset-password' => true])->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::URL."/auth/v1/admin/users/{$id}" && $r['password'] === 'Tedc@2026!');
        $this->assertSame($id, $user->fresh()->auth_id);
    }

    public function test_unconfirmed_email_gets_a_specific_message(): void
    {
        Http::fake([self::URL.'/auth/v1/token*' => Http::response(['code' => 400, 'error_code' => 'email_not_confirmed', 'msg' => 'Email not confirmed'], 400)]);

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@tedc.qa', 'password' => 'secret'], ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('auth.email_not_confirmed', [], 'en'));
    }

    public function test_check_command_explains_why_a_login_is_rejected(): void
    {
        Http::fake([
            self::URL.'/auth/v1/admin/users*' => Http::response(['users' => [['id' => '55555555-5555-5555-5555-555555555555', 'email' => 'diag@tedc.qa', 'email_confirmed_at' => now()->toIso8601String()]]]),
            self::URL.'/auth/v1/token*' => Http::response(['code' => 400, 'error_code' => 'invalid_credentials', 'msg' => 'Invalid login credentials'], 400),
            '*' => Http::response(['ok' => true]),
        ]);
        $this->makeUser(Role::EMPLOYEE, ['email' => 'diag@tedc.qa']);

        $this->artisan('tedc:supabase-check', ['--email' => 'diag@tedc.qa', '--password' => 'wrong'])
            ->expectsOutputToContain('invalid_credentials')
            ->expectsOutputToContain('--reset-password --email=diag@tedc.qa')
            ->assertFailed();
    }
}
