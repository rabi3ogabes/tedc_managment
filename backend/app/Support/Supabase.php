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

    /** Client authorised with the secret (service) key — server only. */
    public static function admin(int $timeout = 30): PendingRequest
    {
        return Http::withHeaders(self::headers((string) config('tedc.supabase.service_role_key')))->timeout($timeout);
    }

    /** Client authorised with the publishable (anon) key. */
    public static function public(int $timeout = 10): PendingRequest
    {
        return Http::withHeaders(['apikey' => (string) config('tedc.supabase.anon_key')])->timeout($timeout);
    }

    public static function jwksUrl(): string
    {
        return config('tedc.supabase.jwks_url') ?: self::url('/auth/v1/.well-known/jwks.json');
    }
}
