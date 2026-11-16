<?php

namespace App\Integrations;

/** HMAC-SHA256 over "timestamp.body", with a tolerance so a captured request cannot be replayed later. */
class WebhookSignature
{
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function verify(string $secret, ?string $timestamp, ?string $signature, string $body, int $tolerance = 300): bool
    {
        if ($secret === '' || ! $timestamp || ! $signature || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }
        $given = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

        return hash_equals(self::sign($secret, $timestamp, $body), strtolower($given));
    }
}
