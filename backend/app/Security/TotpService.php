<?php

namespace App\Security;

/** Time-based one-time passwords (RFC 6238: HMAC-SHA1, 30-second steps, 6 digits) for authenticator apps. */
class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function newSecret(): string
    {
        return $this->base32(random_bytes(20));
    }

    public function otpauthUrl(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), $this->decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Accepts the previous, current and next step so a slightly slow clock still works. */
    public function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $time ??= time();
        foreach ([-30, 0, 30] as $drift) {
            if (hash_equals($this->code($secret, $time + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    private function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            $bits .= $pos === false ? '' : str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
