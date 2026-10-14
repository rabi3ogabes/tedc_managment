<?php

namespace Tests\Feature;

use App\Integrations\IntegrationManager;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Role;
use App\Models\SsoState;
use App\Models\User;
use App\Models\UserIdentity;
use App\Security\Sso\SamlSp;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Tests\TestCase;

/** NFR-07: SAML 2.0 sign-in beside OpenID Connect, through the same provisioning and one-time code. */
class SamlSsoTest extends TestCase
{
    private const IDP = 'https://idp.test/metadata';

    private const NS_SAML = 'urn:oasis:names:tc:SAML:2.0:assertion';

    /** @var array{key: string, cert: string} */
    private array $idp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);   // many sign-ins in a row
        $this->idp = $this->keyPair('Test IdP');
        app(IntegrationManager::class)->update('saml', ['driver' => 'saml', 'enabled' => true, 'settings' => [
            'idp_entity_id' => self::IDP, 'idp_sso_url' => 'https://idp.test/sso', 'idp_slo_url' => 'https://idp.test/slo', 'idp_certificates' => $this->idp['cert'], 'jit' => true,
            'group_map' => json_encode([['group' => 'TRAINERS', 'role' => 'trainer'], ['group' => 'ROOT', 'role' => 'super_admin']]),
        ]]);
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->postJson('/api/v1/admin/sso/saml/keys')->assertOk()->assertJsonPath('data.current.subject', 'CN=TEDC SAML');
        $this->withHeader('Authorization', '');
    }

    private function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();   // guards memoise the user between requests of a test

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @return array{key: string, cert: string} */
    private function keyPair(string $cn): array
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $x = openssl_csr_sign(openssl_csr_new(['commonName' => $cn], $k, ['digest_alg' => 'sha256']), null, $k, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x, $cert);
        openssl_pkey_export($k, $key);

        return ['key' => $key, 'cert' => $cert];
    }

    /** @return array{request_id: string, relay: string, url: string} */
    private function begin(array $query = []): array
    {
        $url = $this->getJson('/api/v1/auth/sso/start'.($query ? '?'.http_build_query($query) : ''))->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://idp.test/sso?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $xml = gzinflate(base64_decode($q['SAMLRequest']));
        $this->assertMatchesRegularExpression('/<samlp:AuthnRequest\s/', $xml);
        $this->assertArrayHasKey('Signature', $q, 'the AuthnRequest is signed');
        preg_match('/ ID="([^"]+)"/', $xml, $m);

        return ['request_id' => $m[1], 'relay' => $q['RelayState'], 'url' => $url];
    }

    private function iso(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /** A Response from the test IdP. Options: sign, tamper, encrypt, audience, recipient, destination, expires, in_response_to, name_id, groups, assertion_id. */
    private function response(string $inResponseTo, array $o = []): string
    {
        $now = time();
        $acs = url('/api/v1/auth/saml/acs');
        $irt = $inResponseTo === '' ? '' : ' InResponseTo="'.$inResponseTo.'"';
        $until = $o['expires'] ?? $now + 300;
        $attrs = ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress' => ['Sara@Moe.Gov.QA'], 'http://schemas.microsoft.com/identity/claims/objectidentifier' => ['oid-77'],
            'http://schemas.microsoft.com/identity/claims/displayname' => ['Sara Ali'], 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups' => $o['groups'] ?? ['TRAINERS', 'ROOT']];
        $attrXml = '';
        foreach ($attrs as $name => $values) {
            $attrXml .= '<saml:Attribute Name="'.$name.'">'.implode('', array_map(fn ($v) => '<saml:AttributeValue xsi:type="xs:string">'.htmlspecialchars($v).'</saml:AttributeValue>', $values)).'</saml:Attribute>';
        }
        $assertion = '<saml:Assertion xmlns:saml="'.self::NS_SAML.'" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ID="'.($o['assertion_id'] ?? '_a'.bin2hex(random_bytes(12))).'" Version="2.0" IssueInstant="'.$this->iso($now).'">'
            .'<saml:Issuer>'.self::IDP.'</saml:Issuer>'
            .'<saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">'.($o['name_id'] ?? 'sara@moe.gov.qa').'</saml:NameID>'
            .'<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="'.$this->iso($until).'" Recipient="'.($o['recipient'] ?? $acs).'"'.$irt.'/></saml:SubjectConfirmation></saml:Subject>'
            .'<saml:Conditions NotBefore="'.$this->iso($o['not_before'] ?? $now - 60).'" NotOnOrAfter="'.$this->iso($until).'"><saml:AudienceRestriction><saml:Audience>'.($o['audience'] ?? url('/api/v1/auth/saml/metadata')).'</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
            .'<saml:AuthnStatement AuthnInstant="'.$this->iso($now).'" SessionIndex="'.($o['session_index'] ?? '_session-1').'"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>'
            .'<saml:AttributeStatement>'.$attrXml.'</saml:AttributeStatement></saml:Assertion>';
        if ($o['sign'] ?? true) {
            $assertion = $this->sign($assertion, $this->idp['key'], $this->idp['cert']);
        }
        if (! empty($o['tamper'])) {
            $assertion = str_replace('oid-77', 'oid-99', $assertion);   // after signing
        }
        if (! empty($o['smuggle'])) {
            // Signature wrapping: an unsigned assertion for someone else placed before the genuine signed one.
            $evil = preg_replace(['/ ID="[^"]+"/', '/oid-77/', '/<ds:Signature.*<\/ds:Signature>/s'], [' ID="_evil"', 'oid-99', ''], $assertion);
            $assertion = $evil.$assertion;
        }
        if (! empty($o['encrypt'])) {
            $assertion = $this->encrypt($assertion, (string) app(IntegrationManager::class)->settings('saml')['sp_certificate']);
        }

        return base64_encode('<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="'.self::NS_SAML.'" ID="_r'.bin2hex(random_bytes(12)).'" Version="2.0" IssueInstant="'.$this->iso($now).'" Destination="'.($o['destination'] ?? $acs).'"'.$irt.'>'
            .'<saml:Issuer>'.self::IDP.'</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'.$assertion.'</samlp:Response>');
    }

    /** Signs the root element, with the signature right after its Issuer (where the schema puts it). */
    private function sign(string $xml, string $key, string $cert): string
    {
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $dsig = new XMLSecurityDSig;
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReference($doc->documentElement, XMLSecurityDSig::SHA256, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID', 'overwrite' => false]);
        $k = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $k->loadKey($key);
        $dsig->sign($k);
        $dsig->add509Cert($cert, true);
        $dsig->insertSignature($doc->documentElement, $doc->getElementsByTagNameNS(self::NS_SAML, 'Issuer')->item(0)->nextSibling);

        return $doc->saveXML($doc->documentElement);
    }

    /** Encrypts the (signed) assertion for the SP, as an IdP does. */
    private function encrypt(string $assertion, string $spCert): string
    {
        $doc = new \DOMDocument;
        $doc->loadXML($assertion);
        $sym = new XMLSecurityKey(XMLSecurityKey::AES256_CBC);
        $sym->generateSessionKey();
        $pub = new XMLSecurityKey(XMLSecurityKey::RSA_OAEP_MGF1P, ['type' => 'public']);
        $pub->loadKey($spCert, false, true);
        $enc = new XMLSecEnc;
        $enc->setNode($doc->documentElement);
        $enc->type = XMLSecEnc::Element;
        $enc->encryptKey($pub, $sym);
        $data = $enc->encryptNode($sym, false);
        $out = new \DOMDocument;
        $out->loadXML('<saml:EncryptedAssertion xmlns:saml="'.self::NS_SAML.'"/>');
        $out->documentElement->appendChild($out->importNode($data, true));

        return $out->saveXML($out->documentElement);
    }

    private function acs(string $response, string $relay)
    {
        return $this->post('/api/v1/auth/saml/acs', ['SAMLResponse' => $response, 'RelayState' => $relay]);
    }

    private function refused(string $response, string $relay): void
    {
        $this->assertStringContainsString('/login?sso_error=1', (string) $this->acs($response, $relay)->assertRedirect()->headers->get('Location'));
    }

    public function test_a_valid_signed_response_signs_in_maps_roles_and_exchanges_a_one_time_code(): void
    {
        $this->assertTrue($this->getJson('/api/v1/auth/options')->json('data.sso'));
        $b = $this->begin();

        $loc = (string) $this->acs($this->response($b['request_id']), $b['relay'])->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString(rtrim((string) config('tedc.web_url'), '/').'/sso/callback?code=', $loc);

        $user = User::where('email', 'sara@moe.gov.qa')->firstOrFail();
        $this->assertSame('Sara Ali', $user->name);
        $this->assertEqualsCanonicalizing([Role::EMPLOYEE, Role::TRAINER], $user->roles()->pluck('slug')->all(), 'a directory group never makes a super administrator');
        $this->assertSame('oid-77', UserIdentity::where(['user_id' => $user->id, 'provider' => 'saml'])->value('subject'));

        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        $session = $this->postJson('/api/v1/auth/sso/exchange', ['code' => $q['code']])->assertOk();
        $this->assertSame($user->id, $session->json('user.id'));
        $this->assertSame('sso', AuthSession::where('user_id', $user->id)->value('method'));
    }

    public function test_an_encrypted_assertion_is_accepted(): void
    {
        $b = $this->begin();
        $this->assertStringContainsString('/sso/callback?code=', (string) $this->acs($this->response($b['request_id'], ['encrypt' => true]), $b['relay'])->headers->get('Location'));
    }

    public function test_unsigned_tampered_misdirected_expired_replayed_and_unsolicited_responses_are_refused(): void
    {
        $b = $this->begin();
        $this->refused($this->response($b['request_id'], ['sign' => false]), $b['relay']);
        $b = $this->begin();
        $this->refused($this->response($b['request_id'], ['tamper' => true]), $b['relay']);
        $b = $this->begin();
        $this->refused($this->response($b['request_id'], ['audience' => 'https://someone-else.test/sp']), $b['relay']);
        $b = $this->begin();
        $this->refused($this->response($b['request_id'], ['recipient' => 'https://someone-else.test/acs', 'destination' => 'https://someone-else.test/acs']), $b['relay']);
        $b = $this->begin();
        $this->refused($this->response($b['request_id'], ['expires' => time() - 181]), $b['relay']);   // past the 3-minute skew
        $b = $this->begin();
        $this->refused($this->response(''), $b['relay']);                                              // no InResponseTo
        $b = $this->begin();
        $this->refused($this->response('_not-our-request'), $b['relay']);
        $this->assertSame(0, User::where('email', 'sara@moe.gov.qa')->count());

        $b = $this->begin();
        $this->refused($this->response($b['request_id'], ['smuggle' => true]), $b['relay']);
        $b = $this->begin();
        $this->refused($this->response($b['request_id'], ['not_before' => time() + 190]), $b['relay']);   // not valid yet, beyond the skew
        $this->assertSame(0, User::where('email', 'sara@moe.gov.qa')->count());

        // Within the 3-minute skew it is still accepted (both edges); the same assertion never works twice.
        $b = $this->begin();
        $replayed = $this->response($b['request_id'], ['expires' => time() - 170, 'assertion_id' => '_once']);
        $this->assertStringContainsString('code=', (string) $this->acs($replayed, $b['relay'])->headers->get('Location'));
        $b2 = $this->begin();
        $this->refused($this->response($b2['request_id'], ['assertion_id' => '_once']), $b2['relay']);
        $b3 = $this->begin();
        $this->assertStringContainsString('code=', (string) $this->acs($this->response($b3['request_id'], ['not_before' => time() + 170]), $b3['relay'])->headers->get('Location'));
        // A relay state works once.
        $this->refused($replayed, $b['relay']);
    }

    public function test_the_metadata_is_valid_xml_with_the_endpoints_and_keys(): void
    {
        $xml = $this->get('/api/v1/auth/saml/metadata')->assertOk()->assertHeader('Content-Type', 'application/samlmetadata+xml')->getContent();
        $this->assertSame([], app(SamlSp::class)->validateMetadata($xml));
        $this->assertStringContainsString('entityID="'.url('/api/v1/auth/saml/metadata').'"', $xml);
        $this->assertStringContainsString('Location="'.url('/api/v1/auth/saml/acs').'"', $xml);
        $this->assertStringContainsString('Location="'.url('/api/v1/auth/saml/slo').'"', $xml);
        $this->assertStringContainsString('use="signing"', $xml);
        $this->assertStringContainsString('use="encryption"', $xml);
    }

    public function test_single_logout_works_both_ways(): void
    {
        $b = $this->begin();
        parse_str(parse_url((string) $this->acs($this->response($b['request_id']), $b['relay'])->headers->get('Location'), PHP_URL_QUERY), $q);
        $token = $this->postJson('/api/v1/auth/sso/exchange', ['code' => $q['code']])->json('access_token');

        // TEDC → IdP: signing out sends a signed LogoutRequest with the NameID and session index.
        $out = $this->bearer($token)->postJson('/api/v1/auth/logout')->assertOk()->json('data.sso_logout_url');
        $this->assertStringStartsWith('https://idp.test/slo?', $out);
        parse_str(parse_url($out, PHP_URL_QUERY), $lq);
        $req = gzinflate(base64_decode($lq['SAMLRequest']));
        $this->assertStringContainsString('sara@moe.gov.qa', $req);
        $this->assertStringContainsString('_session-1', $req);
        $this->assertArrayHasKey('Signature', $lq);
        preg_match('/ ID="([^"]+)"/', $req, $m);
        // The IdP answers; TEDC sends the browser to its sign-in page.
        $answer = '<samlp:LogoutResponse xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="'.self::NS_SAML.'" ID="_lr1" Version="2.0" IssueInstant="'.$this->iso(time()).'" Destination="'.url('/api/v1/auth/saml/slo').'" InResponseTo="'.$m[1].'">'
            .'<saml:Issuer>'.self::IDP.'</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status></samlp:LogoutResponse>';
        $this->assertStringContainsString('/login', (string) $this->get('/api/v1/auth/saml/slo?'.$this->redirectQuery('SAMLResponse', $answer, $lq['RelayState'] ?? null))->assertRedirect()->headers->get('Location'));

        // IdP → TEDC: a signed LogoutRequest ends the person's TEDC session and is answered.
        $b = $this->begin();
        parse_str(parse_url((string) $this->acs($this->response($b['request_id'], ['session_index' => '_session-2']), $b['relay'])->headers->get('Location'), PHP_URL_QUERY), $q);
        $token = $this->postJson('/api/v1/auth/sso/exchange', ['code' => $q['code']])->json('access_token');
        $this->bearer($token)->getJson('/api/v1/auth/me')->assertOk();
        $request = '<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="'.self::NS_SAML.'" ID="_idp-logout" Version="2.0" IssueInstant="'.$this->iso(time()).'" Destination="'.url('/api/v1/auth/saml/slo').'">'
            .'<saml:Issuer>'.self::IDP.'</saml:Issuer><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">sara@moe.gov.qa</saml:NameID><samlp:SessionIndex>_session-2</samlp:SessionIndex></samlp:LogoutRequest>';
        // Unsigned, it is refused and nothing ends.
        $this->get('/api/v1/auth/saml/slo?SAMLRequest='.urlencode(base64_encode(gzdeflate($request))))->assertStatus(400);
        $this->bearer($token)->getJson('/api/v1/auth/me')->assertOk();
        $reply = (string) $this->get('/api/v1/auth/saml/slo?'.$this->redirectQuery('SAMLRequest', $request, null))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://idp.test/slo?', $reply);
        parse_str(parse_url($reply, PHP_URL_QUERY), $rq);
        $this->assertStringContainsString('InResponseTo="_idp-logout"', gzinflate(base64_decode($rq['SAMLResponse'])));
        $this->bearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** The HTTP-Redirect binding: deflated message, RelayState, SigAlg and the IdP's signature over them. */
    private function redirectQuery(string $param, string $xml, ?string $relay): string
    {
        $q = $param.'='.urlencode(base64_encode(gzdeflate($xml)));
        if ($relay !== null) {
            $q .= '&RelayState='.urlencode($relay);
        }
        $q .= '&SigAlg='.urlencode(XMLSecurityKey::RSA_SHA256);
        openssl_sign($q, $sig, $this->idp['key'], OPENSSL_ALGO_SHA256);

        return $q.'&Signature='.urlencode(base64_encode($sig));
    }

    public function test_the_sp_keys_rotate_with_the_next_key_published_first(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $first = (string) app(IntegrationManager::class)->settings('saml')['sp_certificate'];
        $this->asUser($admin)->postJson('/api/v1/admin/sso/saml/keys', ['stage' => 'next'])->assertOk()->assertJsonPath('data.next.subject', 'CN=TEDC SAML');
        $xml = $this->get('/api/v1/auth/saml/metadata')->getContent();
        $this->assertSame(4, substr_count($xml, '<md:KeyDescriptor'), 'current and next keys, each for signing and encryption');

        $this->asUser($admin)->postJson('/api/v1/admin/sso/saml/keys', ['stage' => 'promote'])->assertOk()->assertJsonPath('data.next', null);
        $this->assertNotSame($first, (string) app(IntegrationManager::class)->settings('saml')['sp_certificate']);
        $this->assertArrayNotHasKey('sp_private_key', $this->asUser($admin)->getJson('/api/v1/admin/sso/saml')->assertOk()->json('data.settings'), 'the private key never reaches the browser');
    }

    /** The request id inside a redirect to the IdP. */
    private function requestIdIn(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        preg_match('/ ID="([^"]+)"/', gzinflate(base64_decode($q['SAMLRequest'])), $m);

        return ['request_id' => $m[1], 'relay' => $q['RelayState'], 'query' => $q];
    }

    public function test_the_saml_login_address_sends_a_signed_request_with_a_single_use_state(): void
    {
        $loc = (string) $this->get('/api/v1/auth/saml/login?redirect='.urlencode('https://evil.example/steal'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://idp.test/sso?', $loc);
        $b = $this->requestIdIn($loc);
        $this->assertArrayHasKey('Signature', $b['query'], 'the AuthnRequest is signed');
        $this->assertNull(SsoState::where('state', $b['relay'])->value('redirect_after'), 'a foreign address never receives a sign-in');

        $this->assertStringContainsString('/sso/callback?code=', (string) $this->acs($this->response($b['request_id']), $b['relay'])->headers->get('Location'));
        $this->refused($this->response($b['request_id']), $b['relay']);   // the state worked once

        app(IntegrationManager::class)->update('saml', ['enabled' => false]);
        $this->get('/api/v1/auth/saml/login')->assertNotFound();
    }

    public function test_an_existing_account_is_linked_by_email_only_for_the_identity_providers_own_domains(): void
    {
        $sara = $this->makeUser(Role::EMPLOYEE, ['email' => 'sara@moe.gov.qa']);
        // Nothing says this IdP speaks for moe.gov.qa: matching an existing account by e-mail could hand it to someone else.
        $b = $this->begin();
        $this->refused($this->response($b['request_id']), $b['relay']);
        $this->assertSame(0, UserIdentity::where('user_id', $sara->id)->count());

        app(IntegrationManager::class)->update('saml', ['settings' => ['allowed_domains' => 'moe.gov.qa']]);
        $b = $this->begin();
        parse_str(parse_url((string) $this->acs($this->response($b['request_id']), $b['relay'])->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame($sara->id, $this->postJson('/api/v1/auth/sso/exchange', ['code' => $q['code']])->assertOk()->json('user.id'));
        $this->assertSame('oid-77', UserIdentity::where(['user_id' => $sara->id, 'provider' => 'saml'])->value('subject'));
    }

    public function test_an_administrator_tests_sign_in_before_switching_it_on_and_nobody_is_signed_in_or_created(): void
    {
        app(IntegrationManager::class)->update('saml', ['enabled' => false]);
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $b = $this->requestIdIn($this->asUser($admin)->postJson('/api/v1/admin/sso/test', ['protocol' => 'saml'])->assertOk()->json('data.url'));
        $this->withHeader('Authorization', '');

        $loc = (string) $this->acs($this->response($b['request_id']), $b['relay'])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(rtrim((string) config('tedc.web_url'), '/').'/admin/integrations?sso_test=', $loc);
        $this->assertSame(0, User::where('email', 'sara@moe.gov.qa')->count(), 'a test creates no one');
        $this->assertSame(0, AuthSession::where('method', 'sso')->count(), 'and signs no one in');

        parse_str(parse_url($loc, PHP_URL_QUERY), $r);
        $res = $this->asUser($admin)->getJson('/api/v1/admin/sso/test/'.$r['sso_test'])->assertOk()
            ->assertJsonPath('data.ok', true)->assertJsonPath('data.protocol', 'saml')
            ->assertJsonPath('data.claims.email', 'sara@moe.gov.qa')->assertJsonPath('data.claims.subject', 'oid-77')->assertJsonPath('data.claims.name', 'Sara Ali')
            ->assertJsonPath('data.account.outcome', 'create');
        $this->assertEqualsCanonicalizing([Role::EMPLOYEE, Role::TRAINER], array_column($res->json('data.account.roles'), 'role'));
        $this->assertSame(['ROOT'], array_column($res->json('data.account.ignored'), 'group'), 'a group mapped to super administrator is shown as ignored');

        // Only the administrator who ran it reads it.
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/sso/test/'.$r['sso_test'])->assertNotFound();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/sso/test', ['protocol' => 'saml'])->assertForbidden();

        // A refused answer says why, in plain terms.
        $b = $this->requestIdIn($this->asUser($admin)->postJson('/api/v1/admin/sso/test', ['protocol' => 'saml'])->json('data.url'));
        $this->withHeader('Authorization', '');
        parse_str(parse_url((string) $this->acs($this->response($b['request_id'], ['audience' => 'https://someone-else.test/sp']), $b['relay'])->headers->get('Location'), PHP_URL_QUERY), $r);
        $this->asUser($admin)->getJson('/api/v1/admin/sso/test/'.$r['sso_test'])->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.reason', 'invalid');
    }

    /** IdP metadata as Entra ID and ADFS publish it. @param list<string> $certs */
    private function idpMetadata(string $entityId, array $certs): string
    {
        $keys = implode('', array_map(fn ($c) => '<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:X509Data><ds:X509Certificate>'
            .preg_replace('/-----[A-Z ]+-----|\s+/', '', $c).'</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>', $certs));

        return '<?xml version="1.0"?><md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="'.$entityId.'"><md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            .$keys.'<md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.test/slo2"/>'
            .'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.test/sso2"/></md:IDPSSODescriptor></md:EntityDescriptor>';
    }

    public function test_the_idp_metadata_is_refreshed_from_its_address_for_the_same_identity_provider_only(): void
    {
        $next = $this->keyPair('Test IdP next');
        $hub = app(IntegrationManager::class);
        $hub->update('saml', ['settings' => ['idp_metadata_url' => 'https://idp.test/federationmetadata.xml']]);
        Http::fake(['idp.test/federationmetadata.xml' => Http::sequence()
            ->push($this->idpMetadata(self::IDP, [$this->idp['cert'], $next['cert']]))
            ->push($this->idpMetadata(self::IDP, [$this->idp['cert'], $next['cert']]))
            ->push($this->idpMetadata('https://other-idp.test/', [$next['cert']]))
            ->push('<not-metadata/>')
            ->push('', 500)]);

        // The IdP publishes its next certificate before switching: TEDC trusts both from the next refresh on.
        $this->artisan('tedc:sso-metadata-refresh')->assertSuccessful();
        $s = $hub->settings('saml');
        $this->assertCount(2, SamlSp::certificates((string) $s['idp_certificates']));
        $this->assertSame('https://idp.test/sso2', $s['idp_sso_url']);
        $this->assertSame('https://idp.test/slo2', $s['idp_slo_url']);
        $this->assertSame(1, AuditLog::where('action', 'saml_idp_metadata_refreshed')->count());

        // Unchanged metadata changes nothing; another identity provider, unreadable XML or an error are refused and reported.
        $this->artisan('tedc:sso-metadata-refresh')->assertSuccessful();
        $this->artisan('tedc:sso-metadata-refresh')->assertFailed();
        $this->artisan('tedc:sso-metadata-refresh')->assertFailed();
        $this->artisan('tedc:sso-metadata-refresh')->assertFailed();
        $this->assertSame($s['idp_certificates'], $hub->settings('saml')['idp_certificates']);
        $this->assertSame(self::IDP, $hub->settings('saml')['idp_entity_id']);
        $this->assertSame(1, AuditLog::where('action', 'saml_idp_metadata_refreshed')->count());
        $this->assertFalse($this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/sso/saml')->assertOk()->json('data.metadata_refresh.ok'));
    }
}
