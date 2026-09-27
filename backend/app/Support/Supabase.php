<?php

namespace App\Support;

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

    /** Base HTTP client for Supabase, verifying TLS with the configured CA bundle when one is set. */
    public static function http(int $timeout = 10): PendingRequest
    {
        $request = Http::timeout($timeout);
        $bundle = (string) config('tedc.supabase.ca_bundle');

        return $bundle !== '' ? $request->withOptions(['verify' => $bundle]) : $request;
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
