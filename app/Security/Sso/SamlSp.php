<?php

namespace App\Security\Sso;

use App\Integrations\IntegrationAudit;
use App\Integrations\IntegrationManager;
use App\Models\SsoReplay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * TEDC as a SAML 2.0 service provider (NFR-07), built on onelogin/php-saml in strict mode: signed assertions (the signed
 * element is the one used, so signature wrapping fails), audience, recipient, destination, time with at most 3 minutes of
 * skew, InResponseTo of a request TEDC made, a replay register of assertion ids, optional encrypted assertions, signed
 * requests and single logout both ways. Messages are checked against TEDC's own configured addresses, never against
 * whatever host a proxy reports.
 */
class SamlSp
{
    public function __construct(private readonly IntegrationManager $hub) {}

    public function enabled(): bool
    {
        return $this->hub->get('saml')->enabled && $this->configured();
    }

    /** The IdP and TEDC's key are set up (switched on or not: an administrator can test sign-in first). */
    public function configured(): bool
    {
        $s = $this->hub->settings('saml');

        return filled($s['idp_sso_url'] ?? null) && filled($s['idp_certificates'] ?? null) && filled($s['sp_private_key'] ?? null);
    }

    /** @return array{entity_id: string, acs: string, slo: string, metadata: string} */
    public function urls(): array
    {
        $metadata = url('/api/v1/auth/saml/metadata');

        return ['entity_id' => (string) ($this->hub->settings('saml')['sp_entity_id'] ?? '') ?: $metadata, 'acs' => url('/api/v1/auth/saml/acs'), 'slo' => url('/api/v1/auth/saml/slo'), 'metadata' => $metadata];
    }

    /** @return list<string> the PEM blocks in a pasted list of certificates */
    public static function certificates(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m);

        return $m[0] !== [] ? $m[0] : (trim($pem) !== '' ? [trim($pem)] : []);
    }

    /**
     * The php-saml settings. Logout messages must be signed by the IdP (a forged logout would end someone's session);
     * a sign-in response must at least carry a signed assertion, which is what Entra ID and ADFS send by default.
     *
     * @return array<string, mixed>
     */
    public function config(bool $forLogout = false): array
    {
        $s = $this->hub->settings('saml');
        $u = $this->urls();
        $idp = self::certificates((string) ($s['idp_certificates'] ?? ''));

        return [
            'strict' => true,
            'debug' => false,
            'sp' => array_filter([
                'entityId' => $u['entity_id'],
                'assertionConsumerService' => ['url' => $u['acs'], 'binding' => Constants::BINDING_HTTP_POST],
                'singleLogoutService' => ['url' => $u['slo'], 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
                'x509cert' => (string) ($s['sp_certificate'] ?? ''),
                'privateKey' => (string) ($s['sp_private_key'] ?? ''),
                'x509certNew' => (string) ($s['sp_next_certificate'] ?? ''),
            ], fn ($v) => $v !== ''),
            'idp' => [
                'entityId' => (string) ($s['idp_entity_id'] ?? ''),
                'singleSignOnService' => ['url' => (string) ($s['idp_sso_url'] ?? ''), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'singleLogoutService' => ['url' => (string) ($s['idp_slo_url'] ?? ''), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509certMulti' => ['signing' => $idp, 'encryption' => array_slice($idp, 0, 1)],
            ],
            'security' => [
                'authnRequestsSigned' => true, 'logoutRequestSigned' => true, 'logoutResponseSigned' => true, 'signMetadata' => false,
                'wantMessagesSigned' => $forLogout, 'wantAssertionsSigned' => true, 'wantAssertionsEncrypted' => (bool) ($s['want_encrypted'] ?? false),
                'wantNameId' => true, 'wantNameIdEncrypted' => false, 'requestedAuthnContext' => false, 'wantXMLValidation' => true,
                'relaxDestinationValidation' => false, 'destinationStrictlyMatches' => true, 'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256, 'digestAlgorithm' => XMLSecurityDSig::SHA256, 'lowercaseUrlencoding' => false,
            ],
        ];
    }

    /** TEDC's metadata, with the next key published beside the current one during a rollover. */
    public function metadata(): string
    {
        $settings = new Settings($this->config(), true);
        $xml = $settings->getSPMetadata(true);
        $errors = $settings->validateMetadata($xml);
        if ($errors !== []) {
            throw new RuntimeException('Invalid SP metadata: '.implode(', ', $errors));
        }

        return $xml;
    }

    /** @return list<string> */
    public function validateMetadata(string $xml): array
    {
        return (new Settings($this->config(), true))->validateMetadata($xml);
    }

    /** A signed AuthnRequest (HTTP-Redirect). @return array{url: string, request_id: string} */
    public function loginUrl(string $relayState): array
    {
        $auth = new Auth($this->config());
        $url = $auth->login($relayState, [], false, false, true);

        return ['url' => $url, 'request_id' => (string) $auth->getLastRequestID()];
    }

    /**
     * Checks the IdP's answer to the request TEDC made and returns who signed in.
     *
     * @return array{subject: string, name_id: string, name_id_format: ?string, session_index: ?string, email: ?string, upn: ?string, name: ?string, name_ar: ?string, employee_no: ?string, groups: list<string>}
     *
     * @throws SamlRefused
     */
    public function acs(string $samlResponse, string $requestId): array
    {
        return $this->pinned($this->urls()['acs'], function () use ($samlResponse, $requestId) {
            try {
                $r = new Response(new Settings($this->config()), $samlResponse);
            } catch (\Throwable $e) {
                throw new SamlRefused('malformed', $e->getMessage());
            }
            // Only answers to a request TEDC made: the response must name it.
            $irt = $r->getXMLDocument()->documentElement->getAttribute('InResponseTo');
            if ($irt === '' || ! hash_equals($requestId, $irt)) {
                throw new SamlRefused('unsolicited');
            }
            if (! $r->isValid($requestId)) {
                throw new SamlRefused('invalid', (string) $r->getError());
            }
            $this->remember((string) $r->getAssertionId(), (int) ($r->getAssertionNotOnOrAfter() ?: time() + 300));

            $s = $this->hub->settings('saml');
            $attrs = $r->getAttributes();
            $first = fn (?string $name) => $name && isset($attrs[$name][0]) ? trim((string) $attrs[$name][0]) : null;
            $nameId = (string) $r->getNameId();
            $email = $first($s['attr_email'] ?? null) ?? (filter_var($nameId, FILTER_VALIDATE_EMAIL) ? $nameId : null);

            return [
                'subject' => $first($s['attr_object_id'] ?? null) ?? $nameId,
                'name_id' => $nameId, 'name_id_format' => $r->getNameIdFormat(), 'session_index' => $r->getSessionIndex(),
                'email' => $email, 'upn' => $first($s['attr_upn'] ?? null) ?? $email,
                'name' => $first($s['attr_name'] ?? null) ?? $first($s['attr_name_ar'] ?? null), 'name_ar' => $first($s['attr_name_ar'] ?? null),
                'employee_no' => $first($s['attr_employee_no'] ?? null),
                'groups' => array_values(array_map('strval', (array) ($attrs[(string) ($s['attr_groups'] ?? '')] ?? []))),
            ];
        });
    }

    /**
     * The same assertion never signs anyone in twice (every server shares the register). One atomic insert that ignores a
     * duplicate: catching a unique-key error would abort the surrounding transaction on PostgreSQL.
     */
    private function remember(string $assertionId, int $notOnOrAfter): void
    {
        SsoReplay::where('expires_at', '<', now()->subMinutes(5))->delete();
        $now = now();
        $stored = SsoReplay::query()->insertOrIgnore([['id' => (string) Str::uuid(), 'assertion_id' => $assertionId, 'expires_at' => $now->copy()->setTimestamp($notOnOrAfter + Constants::ALLOWED_CLOCK_DRIFT), 'created_at' => $now, 'updated_at' => $now]]);
        if ($stored === 0) {
            throw new SamlRefused('replayed');
        }
    }

    /** A signed LogoutRequest for the IdP. @return array{url: string, request_id: string} */
    public function logoutUrl(string $relayState, string $nameId, ?string $nameIdFormat, ?string $sessionIndex): array
    {
        $auth = new Auth($this->config(true));
        $url = $auth->logout($relayState, [], $nameId, $sessionIndex, true, $nameIdFormat);

        return ['url' => $url, 'request_id' => (string) $auth->getLastRequestID()];
    }

    /**
     * A logout message from the IdP (HTTP-Redirect, signature required): its answer to TEDC's request, or its own request
     * to end a session, which is answered.
     *
     * @return array{kind: 'response'|'request', redirect: ?string, name_id: ?string, session_indexes: list<string>}
     *
     * @throws SamlRefused
     */
    public function logout(Request $request, ?string $expectedRequestId): array
    {
        return $this->pinned($this->urls()['slo'], function () use ($request, $expectedRequestId) {
            $savedGet = $_GET;
            $_GET = $request->query->all();
            try {
                $auth = new Auth($this->config(true));
                $redirect = $auth->processSLO(true, $expectedRequestId, true, null, true);
                if ($auth->getErrors() !== []) {
                    throw new SamlRefused('logout', (string) $auth->getLastErrorReason());
                }
                if (! $request->query->has('SAMLRequest')) {
                    return ['kind' => 'response', 'redirect' => null, 'name_id' => null, 'session_indexes' => []];
                }
                $xml = (string) gzinflate((string) base64_decode((string) $request->query('SAMLRequest'), true));

                return ['kind' => 'request', 'redirect' => is_string($redirect) ? $redirect : null, 'name_id' => LogoutRequest::getNameId($xml),
                    'session_indexes' => array_values(array_map('strval', LogoutRequest::getSessionIndexes($xml)))];
            } catch (SamlRefused $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new SamlRefused('logout', $e->getMessage());
            } finally {
                $_GET = $savedGet;
            }
        });
    }

    /**
     * Runs php-saml with "the current URL" set to TEDC's own configured address (and the raw query string kept for
     * signature checks), so a message is accepted only when it was sent to exactly that address.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function pinned(string $url, callable $fn): mixed
    {
        $u = parse_url($url);
        $saved = ['REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null, 'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? null];
        $scheme = $u['scheme'] ?? 'https';
        Utils::setSelfProtocol($scheme);
        Utils::setSelfHost($u['host'] ?? 'localhost');
        Utils::setSelfPort((string) ($u['port'] ?? ($scheme === 'https' ? 443 : 80)));
        $_SERVER['REQUEST_URI'] = $u['path'] ?? '/';
        $_SERVER['QUERY_STRING'] = (string) (request()?->server('QUERY_STRING') ?? '');
        try {
            return $fn();
        } finally {
            foreach ($saved as $k => $v) {
                if ($v === null) {
                    unset($_SERVER[$k]);
                } else {
                    $_SERVER[$k] = $v;
                }
            }
            Utils::setSelfProtocol(null);
            Utils::setSelfHost(null);
            Utils::setSelfPort(null);
        }
    }

    /**
     * What an IdP's metadata says: entity id, sign-in and logout addresses, and its signing certificates (all of them, so a
     * certificate the IdP publishes ahead of a rollover is trusted from the start).
     *
     * @return array{entity_id: string, sso_url: string, slo_url: ?string, pem: string, certificates: list<array<string, mixed>>}
     *
     * @throws RuntimeException when it is not IdP metadata
     */
    public static function parseMetadata(string $xml): array
    {
        try {
            $idp = IdPMetadataParser::parseXML($xml)['idp'] ?? null;
        } catch (\Throwable) {
            $idp = null;
        }
        if (! $idp || empty($idp['entityId']) || empty($idp['singleSignOnService']['url'])) {
            throw new RuntimeException('not IdP metadata');
        }
        $certs = array_values(array_unique(array_filter(array_merge([$idp['x509cert'] ?? null], $idp['x509certMulti']['signing'] ?? []))));
        if ($certs === []) {
            throw new RuntimeException('no signing certificate');
        }
        $pem = implode("\n", array_map(fn ($c) => "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $c), 64, "\n").'-----END CERTIFICATE-----', $certs));

        return ['entity_id' => (string) $idp['entityId'], 'sso_url' => (string) $idp['singleSignOnService']['url'], 'slo_url' => $idp['singleLogoutService']['url'] ?? null, 'pem' => $pem,
            'certificates' => array_values(array_filter(array_map(fn ($c) => self::describe($c), self::certificates($pem))))];
    }

    /**
     * Reads metadata from an HTTPS address (ten seconds, no redirects, at most 500 kB). The address may not point inside the
     * network: a name that resolves to a private, loopback or link-local address, or such an address itself, is refused.
     *
     * @throws RuntimeException
     */
    public static function fetchMetadata(string $url): string
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('metadata address must be HTTPS');
        }
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);   // a name that does not resolve fails when fetched
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('metadata address points inside the network');
            }
        }
        $r = Http::timeout(10)->withOptions(['allow_redirects' => false])->get($url);
        if (! $r->successful() || strlen($r->body()) > 500000) {
            throw new RuntimeException('metadata address answered '.$r->status());
        }

        return $r->body();
    }

    /**
     * The scheduled refresh of the IdP's metadata from its address: the same identity provider only (another entity id is
     * refused), new certificates and addresses applied and audited, the outcome kept for the health card.
     *
     * @return array{ok: bool, changed: bool, message: ?string, at: string}
     */
    public function refreshMetadata(): array
    {
        $s = $this->hub->settings('saml');
        $url = trim((string) ($s['idp_metadata_url'] ?? ''));
        $result = ['ok' => true, 'changed' => false, 'message' => null, 'at' => now()->toIso8601String()];
        if ($url === '') {
            return $result + ['skipped' => true];
        }
        try {
            $m = self::parseMetadata(self::fetchMetadata($url));
            if (filled($s['idp_entity_id'] ?? null) && $m['entity_id'] !== $s['idp_entity_id']) {
                throw new RuntimeException(__('auth.sso_metadata_other_idp'));
            }
            $new = ['idp_entity_id' => $m['entity_id'], 'idp_sso_url' => $m['sso_url'], 'idp_slo_url' => (string) $m['slo_url'], 'idp_certificates' => $m['pem']];
            $changed = array_filter($new, fn ($v, $k) => (string) ($s[$k] ?? '') !== $v, ARRAY_FILTER_USE_BOTH);
            if ($changed !== []) {
                $this->hub->update('saml', ['settings' => $new]);
                IntegrationAudit::record('saml', 'saml_idp_metadata_refreshed', null,
                    ['changed' => array_keys($changed), 'certificates' => array_column($m['certificates'], 'fingerprint')],
                    ['certificates' => array_column(array_filter(array_map(fn ($c) => self::describe($c), self::certificates((string) ($s['idp_certificates'] ?? '')))), 'fingerprint')]);
                $result['changed'] = true;
            }
        } catch (\Throwable $e) {
            Log::warning('SAML metadata refresh failed', ['error' => $e->getMessage()]);
            $result = ['ok' => false, 'changed' => false, 'message' => mb_substr($e->getMessage(), 0, 300), 'at' => $result['at']];
        }
        Cache::forever('saml.metadata_refresh', $result);

        return $result;
    }

    /**
     * A new SP key pair: RSA 3072, self-signed for five years (the IdP trusts it through the metadata, not a CA).
     *
     * @return array{key: string, cert: string}
     */
    public static function newKeyPair(): array
    {
        // TEDC's own OpenSSL configuration: the host's would add its default subject fields (and differs between hosts).
        $config = ['config' => resource_path('openssl/minimal.cnf'), 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $config);
        $csr = openssl_csr_new(['commonName' => 'TEDC SAML'], $key, $config);
        $x509 = openssl_csr_sign($csr, null, $key, 5 * 365, $config, random_int(1, PHP_INT_MAX));
        openssl_x509_export($x509, $cert);
        openssl_pkey_export($key, $pem);

        return ['key' => $pem, 'cert' => $cert];
    }

    /** What a certificate says, for the settings screen. @return array{subject: string, issuer: string, not_after: ?string, days_left: ?int, fingerprint: string}|null */
    public static function describe(?string $pem): ?array
    {
        if (! $pem || ! ($x = @openssl_x509_read(Utils::formatCert($pem)))) {
            return null;
        }
        $p = openssl_x509_parse($x);
        $until = isset($p['validTo_time_t']) ? now()->setTimestamp((int) $p['validTo_time_t']) : null;
        $name = fn (array $n) => implode(', ', array_map(fn ($k, $v) => $k.'='.(is_array($v) ? implode('/', $v) : $v), array_keys($n), $n));

        return ['subject' => $name($p['subject'] ?? []), 'issuer' => $name($p['issuer'] ?? []), 'not_after' => $until?->toIso8601String(),
            'days_left' => $until ? (int) floor(now()->diffInDays($until, false)) : null, 'fingerprint' => strtoupper(implode(':', str_split((string) openssl_x509_fingerprint($x, 'sha256'), 2)))];
    }
}
