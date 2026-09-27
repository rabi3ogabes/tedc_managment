<?php

namespace App\Support;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Server-side Supabase HTTP client.
 *
 * Supports both key formats:
 *  - new API keys (`sb_secret_…` / `sb_publishable_…`): opaque strings sent only in the `apikey`
 *    header — the Supabase gateway derives the role itself;
 *  - legacy JWT keys (`service_role` / `anon`): sent in `apikey` and as a Bearer token.
 */
class Supabase
{
    public static function url(string $path = ''): string
    {
        return rtrim((string) config('tedc.supabase.url'), '/').$path;
    }

    public static function isLegacyJwtKey(string $key): bool
    {
        return str_starts_with($key, 'eyJ') && substr_count($key, '.') === 2;
    }

    public static function headers(string $key): array
    {
        return self::isLegacyJwtKey($key) ? ['apikey' => $key, 'Authorization' => "Bearer {$key}"] : ['apikey' => $key];
    }

    /** Base HTTP client for Supabase; TLS is always verified against a trusted CA bundle. */
    public static function http(int $timeout = 10): PendingRequest
    {
        return Http::timeout($timeout)->withOptions(['verify' => self::caBundle()]);
    }

    /**
     * CA bundle used to verify Supabase's certificate: SUPABASE_CA_BUNDLE when set, otherwise the
     * system store (php.ini / SSL_CERT_FILE / OS paths) or, when PHP has none — typical on Windows —
     * the Mozilla bundle shipped with composer/ca-bundle.
     */
    public static function caBundle(): string
    {
        $configured = (string) config('tedc.supabase.ca_bundle');

        return $configured !== '' ? $configured : CaBundle::getSystemCaRootBundlePath();
    }

    /** Client authorised with the secret (service) key — server only. */
    public static function admin(int $timeout = 30): PendingRequest
    {
        return self::http($timeout)->withHeaders(self::headers((string) config('tedc.supabase.service_role_key')));
    }

    /** Client authorised with the publishable (anon) key. */
    public static function public(int $timeout = 10): PendingRequest
    {
        return self::http($timeout)->withHeaders(['apikey' => (string) config('tedc.supabase.anon_key')]);
    }

    /** True when a connection failed because PHP could not verify Supabase's TLS certificate. */
    public static function isCertificateError(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'SSL certificate');
    }

    public static function certificateHint(): string
    {
        return 'PHP cannot verify the Supabase HTTPS certificate. Download https://curl.se/ca/cacert.pem and set '
            .'SUPABASE_CA_BUNDLE (or curl.cainfo and openssl.cafile in php.ini) to its full path.';
    }

    public static function jwksUrl(): string
    {
        return config('tedc.supabase.jwks_url') ?: self::url('/auth/v1/.well-known/jwks.json');
    }
}
