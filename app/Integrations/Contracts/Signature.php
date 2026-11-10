<?php

namespace App\Integrations\Contracts;

/**
 * The signatures a contract can name, for requests TEDC sends and callbacks it receives: HMAC-SHA256 or SHA512 with a shared secret,
 * or RSA-SHA256 (TEDC signs with its private key; a callback is verified with the system's public key). What is signed is a template
 * (default "timestamp.body"); the result is hex or base64, with an optional prefix such as "sha256=".
 */
final class Signature
{
    public const ALGORITHMS = ['hmac-sha256', 'hmac-sha512', 'rsa-sha256'];

    /**
     * @param  array<string, mixed>  $spec  algorithm, encoding (hex | base64), payload, prefix
     * @param  string  $key  the shared secret, or the private key (PEM) for RSA
     * @param  array<string, mixed>  $extra  more values the payload template may read (method, path…)
     */
    public static function make(array $spec, string $key, string $timestamp, string $body, array $extra = []): string
    {
        $algorithm = (string) ($spec['algorithm'] ?? 'hmac-sha256');
        if (! in_array($algorithm, self::ALGORITHMS, true)) {
            throw new ContractException('bad_signature', 'The signature algorithm "'.mb_substr($algorithm, 0, 30).'" is not supported.');
        }
        $payload = Template::render((string) ($spec['payload'] ?? '{{ timestamp }}.{{ body }}'), ['timestamp' => $timestamp, 'body' => $body] + $extra);
        if ($algorithm === 'rsa-sha256') {
            $pkey = @openssl_pkey_get_private($key);
            if ($pkey === false || ! openssl_sign($payload, $raw, $pkey, OPENSSL_ALGO_SHA256)) {
                throw new ContractException('bad_signature', 'The signing key is missing or is not a valid private key.');
            }
        } else {
            $raw = hash_hmac(substr($algorithm, 5), $payload, $key, true);
        }

        return (string) ($spec['prefix'] ?? '').self::encode($raw, (string) ($spec['encoding'] ?? 'hex'));
    }

    /**
     * Whether a received signature is genuine. $key is the shared secret, or the public key (PEM) for RSA. $toleranceSeconds is how
     * old a timestamped message may be (null: the time is not checked).
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $extra
     */
    public static function verify(array $spec, string $key, ?string $signature, string $timestamp, string $body, ?int $toleranceSeconds = 300, array $extra = []): bool
    {
        if ($key === '' || $signature === null || $signature === '') {
            return false;
        }
        if ($toleranceSeconds !== null && (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $toleranceSeconds)) {
            return false;
        }
        $prefix = (string) ($spec['prefix'] ?? '');
        $given = $prefix !== '' && str_starts_with($signature, $prefix) ? substr($signature, strlen($prefix)) : $signature;
        if (($spec['algorithm'] ?? 'hmac-sha256') === 'rsa-sha256') {
            $pkey = @openssl_pkey_get_public($key);
            $raw = self::decode($given, (string) ($spec['encoding'] ?? 'hex'));
            $payload = Template::render((string) ($spec['payload'] ?? '{{ timestamp }}.{{ body }}'), ['timestamp' => $timestamp, 'body' => $body] + $extra);

            return $pkey !== false && $raw !== null && openssl_verify($payload, $raw, $pkey, OPENSSL_ALGO_SHA256) === 1;
        }
        try {
            $expected = self::make($spec, $key, $timestamp, $body, $extra);
        } catch (ContractException) {
            return false;
        }

        return hash_equals(strtolower($prefix !== '' ? substr($expected, strlen($prefix)) : $expected), strtolower($given));
    }

    private static function encode(string $raw, string $encoding): string
    {
        return $encoding === 'base64' ? base64_encode($raw) : bin2hex($raw);
    }

    private static function decode(string $text, string $encoding): ?string
    {
        $raw = $encoding === 'base64' ? base64_decode($text, true) : (ctype_xdigit($text) && strlen($text) % 2 === 0 ? hex2bin($text) : false);

        return $raw === false ? null : $raw;
    }
}
