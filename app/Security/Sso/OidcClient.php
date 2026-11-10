<?php

namespace App\Security\Sso;

use App\Support\Supabase;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenID Connect against Microsoft Entra ID: authorization code with PKCE, code exchange, and strict ID-token validation
 * (signature from the published keys, issuer, audience, nonce, expiry with a one-minute clock allowance).
 */
class OidcClient
{
    public const LEEWAY = 60;

    /** @return array<string, mixed> */
    public function discovery(array $s): array
    {
        $tenant = (string) ($s['tenant_id'] ?? 'common');

        return Cache::remember("oidc.discovery.{$tenant}", 3600, function () use ($tenant, $s) {
            $url = ($s['discovery_url'] ?? null) ?: "https://login.microsoftonline.com/{$tenant}/v2.0/.well-known/openid-configuration";
            $r = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(10)->get($url);
            if (! $r->successful() || ! $r->json('authorization_endpoint')) {
                throw new RuntimeException('The identity provider did not answer.');
            }

            return $r->json();
        });
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function authorizationUrl(array $s, string $state, string $nonce, string $verifier): string
    {
        return $this->discovery($s)['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $s['client_id'] ?? '', 'response_type' => 'code', 'redirect_uri' => $s['redirect_uri'] ?? '', 'response_mode' => 'query',
            'scope' => $s['scopes'] ?? 'openid profile email', 'state' => $state, 'nonce' => $nonce, 'code_challenge' => self::challenge($verifier), 'code_challenge_method' => 'S256',
        ]);
    }

    /** @return array<string, mixed> the token response */
    public function exchange(array $s, string $code, string $verifier): array
    {
        $r = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(15)->asForm()->post($this->discovery($s)['token_endpoint'], array_filter([
            'grant_type' => 'authorization_code', 'client_id' => $s['client_id'] ?? '', 'client_secret' => $s['client_secret'] ?? null, 'code' => $code, 'redirect_uri' => $s['redirect_uri'] ?? '', 'code_verifier' => $verifier,
        ]));
        if (! $r->successful() || ! $r->json('id_token')) {
            throw new RuntimeException('The sign-in could not be completed.');
        }

        return $r->json();
    }

    /** @return array<string, mixed> the verified claims @throws RuntimeException */
    public function validate(array $s, string $idToken, string $nonce, ?int $now = null): array
    {
        $d = $this->discovery($s);
        $jwks = Cache::remember('oidc.jwks.'.md5((string) $d['jwks_uri']), 3600, fn () => Http::withOptions(['verify' => Supabase::caBundle()])->timeout(10)->get($d['jwks_uri'])->json());
        $previous = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        $savedTs = JWT::$timestamp;
        if ($now !== null) {
            JWT::$timestamp = $now;
        }
        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($jwks));
        } finally {
            JWT::$leeway = $previous;
            JWT::$timestamp = $savedTs;
        }
        if (($claims['aud'] ?? null) !== ($s['client_id'] ?? null) && ! in_array($s['client_id'] ?? null, (array) ($claims['aud'] ?? []), true)) {
            throw new RuntimeException('Wrong audience.');
        }
        $issuer = str_replace('{tenantid}', (string) ($claims['tid'] ?? ''), (string) ($d['issuer'] ?? ''));
        if ($issuer === '' || ($claims['iss'] ?? null) !== $issuer) {
            throw new RuntimeException('Wrong issuer.');
        }
        if (! isset($claims['nonce']) || ! hash_equals($nonce, (string) $claims['nonce'])) {
            throw new RuntimeException('Wrong nonce.');
        }

        return $claims;
    }
}
