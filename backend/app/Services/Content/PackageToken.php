<?php

namespace App\Services\Content;

/** Short-lived token placed in the path of the content proxy, so the relative links inside a package keep working. */
final class PackageToken
{
    public static function make(string $packageId, ?string $userId = null, int $ttl = 7200): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['p' => $packageId, 'e' => time() + $ttl, 'u' => $userId])), '+/', '-_'), '=');

        return $payload.'.'.self::sign($payload);
    }

    /** @return array{p: string, e: int, u: ?string}|null */
    public static function read(string $token, string $packageId): ?array
    {
        [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || ! hash_equals(self::sign($payload), $sig)) {
            return null;
        }
        $d = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($d) && ($d['p'] ?? null) === $packageId && ($d['e'] ?? 0) >= time() ? $d : null;
    }

    private static function sign(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    public static function url(string $packageId, string $path = '', ?string $userId = null): string
    {
        return '/content/'.self::make($packageId, $userId).'/'.$packageId.'/'.ltrim($path, '/');
    }
}
