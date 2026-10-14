<?php

namespace Tests\Feature;

use App\Integrations\IntegrationManager;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * NFR-07: single sign-on in the app. The app opens the system browser (AppAuth, PKCE) at auth/sso/start, the server finishes
 * OIDC or SAML as for the web and sends a one-time code to the app's own scheme; the code works once, briefly, and only with
 * the app's PKCE verifier.
 */
class MobileSsoExchangeTest extends TestCase
{
    private const APP = 'qa.edu.tedc.app://sso';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);   // many sign-ins in a row
        // The training identity provider: claims are passed through the callback (never in production).
        app(IntegrationManager::class)->update('entra', ['driver' => 'fake', 'enabled' => true, 'settings' => ['jit' => true]]);
        $this->withHeader('Authorization', '');
    }

    private function verifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** Runs the browser part as AppAuth does and returns the app redirect's query. @return array<string, string> */
    private function signIn(string $challenge, array $extra = []): array
    {
        $start = $this->get('/api/v1/auth/sso/start?'.http_build_query($extra + ['client' => 'mobile', 'response_type' => 'code', 'client_id' => 'tedc-mobile', 'redirect_uri' => self::APP,
            'state' => 'app-state-1', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']))->assertRedirect();
        parse_str(parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $q);
        $profile = base64_encode(json_encode(['sub' => 'sub-5', 'oid' => 'oid-5', 'preferred_username' => 'huda@moe.gov.qa', 'name' => 'Huda']));
        $back = (string) $this->get('/api/v1/auth/sso/callback?code=fake&state='.$q['state'].'&profile='.$profile)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::APP.'?', $back);
        parse_str(parse_url($back, PHP_URL_QUERY), $r);

        return $r;
    }

    public function test_the_app_gets_a_one_time_code_bound_to_its_pkce_verifier(): void
    {
        $verifier = $this->verifier();
        $r = $this->signIn($this->challenge($verifier));
        $this->assertSame('app-state-1', $r['state'], 'the app checks its own state');

        $session = $this->postJson('/api/v1/auth/sso/exchange', ['code' => $r['code'], 'code_verifier' => $verifier])->assertOk();
        $this->assertSame(User::where('email', 'huda@moe.gov.qa')->value('id'), $session->json('user.id'));
        $this->assertNotEmpty($session->json('refresh_token'));
        // Once only.
        $this->postJson('/api/v1/auth/sso/exchange', ['code' => $r['code'], 'code_verifier' => $verifier])->assertStatus(400);
    }

    public function test_without_the_verifier_the_code_is_refused_and_burned(): void
    {
        $verifier = $this->verifier();
        $r = $this->signIn($this->challenge($verifier));
        $this->postJson('/api/v1/auth/sso/exchange', ['code' => $r['code']])->assertStatus(400);
        $this->postJson('/api/v1/auth/sso/exchange', ['code' => $r['code'], 'code_verifier' => $verifier])->assertStatus(400);
    }

    public function test_a_wrong_verifier_burns_the_code(): void
    {
        $verifier = $this->verifier();
        $r = $this->signIn($this->challenge($verifier));
        $this->postJson('/api/v1/auth/sso/exchange', ['code' => $r['code'], 'code_verifier' => $this->verifier()])->assertStatus(400);
        $this->postJson('/api/v1/auth/sso/exchange', ['code' => $r['code'], 'code_verifier' => $verifier])->assertStatus(400);
    }

    public function test_the_code_is_short_lived(): void
    {
        $verifier = $this->verifier();
        $r = $this->signIn($this->challenge($verifier));
        $this->travel(91)->seconds();
        $this->postJson('/api/v1/auth/sso/exchange', ['code' => $r['code'], 'code_verifier' => $verifier])->assertStatus(400);
    }

    public function test_only_the_registered_app_address_and_s256_are_accepted(): void
    {
        $c = $this->challenge($this->verifier());
        foreach (['https://evil.test/sso', 'qa.edu.tedc.app://sso.evil.test', 'qa.edu.tedc.app://other', 'tedc://sso', 'javascript:alert(1)'] as $bad) {
            $this->get('/api/v1/auth/sso/start?'.http_build_query(['client' => 'mobile', 'redirect_uri' => $bad, 'state' => 's', 'code_challenge' => $c, 'code_challenge_method' => 'S256']))->assertStatus(422);
        }
        // A plain challenge, or none, is refused: the app must use PKCE with S256.
        $this->get('/api/v1/auth/sso/start?'.http_build_query(['client' => 'mobile', 'redirect_uri' => self::APP, 'state' => 's', 'code_challenge' => $c, 'code_challenge_method' => 'plain']))->assertStatus(422);
        $this->get('/api/v1/auth/sso/start?'.http_build_query(['client' => 'mobile', 'redirect_uri' => self::APP, 'state' => 's']))->assertStatus(422);
        // The web keeps its own allow-list; a foreign address never receives a sign-in.
        $this->assertNull($this->getJson('/api/v1/auth/sso/start?redirect='.urlencode('https://evil.test/x'))->assertOk()->json('data.redirect'));
    }

    public function test_the_public_app_configuration_says_when_sso_is_offered(): void
    {
        $this->assertTrue($this->getJson('/api/v1/public/mobile-config')->assertOk()->json('data.sso'));
        app(IntegrationManager::class)->update('entra', ['enabled' => false]);
        $this->assertFalse($this->getJson('/api/v1/public/mobile-config')->assertOk()->json('data.sso'));
    }
}
