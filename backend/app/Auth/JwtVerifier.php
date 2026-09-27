<?php

namespace App\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Verifies access tokens issued by Supabase Auth (HS256 shared secret or
 * asymmetric keys published on the project's JWKS endpoint) and by the
 * platform itself in "local" auth mode.
 */
class JwtVerifier
{
    public function decode(string $jwt): object
    {
        $header = $this->header($jwt);
        $alg = $header['alg'] ?? null;

        $keys = $alg === 'HS256'
            ? new Key((string) config('tedc.auth.jwt_secret'), 'HS256')
            : $this->jwks();

        $claims = JWT::decode($jwt, $keys);

        $audience = config('tedc.auth.jwt_audience');
        $aud = (array) ($claims->aud ?? []);
        if ($audience && ! in_array($audience, $aud, true)) {
            throw new UnexpectedValueException('Invalid token audience.');
        }
        if (($claims->typ ?? 'access') !== 'access') {
            throw new UnexpectedValueException('Not an access token.');
        }

        return $claims;
    }

    /**
     * Signs a platform token (local auth driver and refresh tokens).
     */
    public function issue(array $claims, int $ttl): string
    {
        $now = time();

        return JWT::encode(array_merge([
            'iss' => config('app.url'),
            'aud' => config('tedc.auth.jwt_audience'),
            'iat' => $now,
            'exp' => $now + $ttl,
            'role' => 'authenticated',
        ], $claims), (string) config('tedc.auth.jwt_secret'), 'HS256');
    }

    public function decodeRefresh(string $jwt): object
    {
        $claims = JWT::decode($jwt, new Key((string) config('tedc.auth.jwt_secret'), 'HS256'));
        if (($claims->typ ?? null) !== 'refresh') {
            throw new UnexpectedValueException('Not a refresh token.');
        }

        return $claims;
    }

    private function header(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Malformed token.');
        }

        return (array) json_decode(JWT::urlsafeB64Decode($parts[0]), true);
    }

    private function jwks(): array
    {
        $url = rtrim((string) config('tedc.supabase.url'), '/').'/auth/v1/.well-known/jwks.json';

        $set = Cache::remember('supabase.jwks', config('tedc.supabase.jwks_cache_seconds'), function () use ($url) {
            try {
                return Http::timeout(5)->get($url)->throw()->json();
            } catch (Throwable $e) {
                throw new UnexpectedValueException('Unable to load signing keys.', 0, $e);
            }
        });

        return JWK::parseKeySet($set);
    }
}
