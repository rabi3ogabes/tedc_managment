<?php

namespace App\Services\Lti;

use RuntimeException;

/** RS256 JSON Web Tokens and the JWK ⇄ PEM conversions LTI 1.3 needs (OpenSSL only, no extra dependency). */
final class Jwt
{
    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }

    public static function sign(array $claims, string $privatePem, string $kid): string
    {
        $head = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $kid]));
        $body = self::b64(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        openssl_sign("{$head}.{$body}", $sig, $privatePem, OPENSSL_ALGO_SHA256);

        return "{$head}.{$body}.".self::b64($sig);
    }

    /** @return array{header: array<string, mixed>, claims: array<string, mixed>} */
    public static function parse(string $jwt): array
    {
        $p = explode('.', $jwt);
        if (count($p) !== 3) {
            throw new RuntimeException('Malformed token.');
        }

        return ['header' => json_decode(self::unb64($p[0]), true) ?: [], 'claims' => json_decode(self::unb64($p[1]), true) ?: []];
    }

    /** Verifies the RS256 signature and the time claims; returns the claims. */
    public static function verify(string $jwt, string $publicPem, int $leeway = 60): array
    {
        $parts = explode('.', $jwt);
        $parsed = self::parse($jwt);
        if (($parsed['header']['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('Only RS256 is accepted.');
        }
        if (openssl_verify("{$parts[0]}.{$parts[1]}", self::unb64($parts[2]), $publicPem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Bad signature.');
        }
        $c = $parsed['claims'];
        if (isset($c['exp']) && time() > $c['exp'] + $leeway) {
            throw new RuntimeException('Token expired.');
        }
        if (isset($c['nbf']) && time() < $c['nbf'] - $leeway) {
            throw new RuntimeException('Token not valid yet.');
        }

        return $c;
    }

    /** @return array{kty: string, alg: string, use: string, kid: string, n: string, e: string} */
    public static function jwk(string $publicPem, string $kid): array
    {
        $d = openssl_pkey_get_details(openssl_pkey_get_public($publicPem));

        return ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => self::b64($d['rsa']['n']), 'e' => self::b64($d['rsa']['e'])];
    }

    /** Builds a PEM public key from a JWK's modulus and exponent. */
    public static function pemFromJwk(array $jwk): string
    {
        $n = self::unb64($jwk['n']);
        $e = self::unb64($jwk['e']);
        $int = fn (string $b) => "\x02".self::len(strlen(($b[0] >= "\x80" ? "\x00" : '').$b)).($b[0] >= "\x80" ? "\x00" : '').$b;
        $seq = "\x30".self::len(strlen($int($n).$int($e))).$int($n).$int($e);
        $bit = "\x03".self::len(strlen($seq) + 1)."\x00".$seq;
        $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $der = "\x30".self::len(strlen($algo.$bit)).$algo.$bit;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private static function len(int $l): string
    {
        if ($l < 128) {
            return chr($l);
        }
        $b = ltrim(pack('N', $l), "\x00");

        return chr(0x80 | strlen($b)).$b;
    }
}
