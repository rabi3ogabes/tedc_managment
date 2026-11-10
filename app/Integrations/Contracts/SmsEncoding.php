<?php

namespace App\Integrations\Contracts;

/**
 * What a text message is made of: GSM 7-bit (the default alphabet and its extension table, 160 places in one message, 153 in each part of
 * a longer one) when every character is in it, UCS-2 (UTF-16, 70 and 67) when even one is not, which is the case for any Arabic text.
 */
final class SmsEncoding
{
    /** The GSM 03.38 default alphabet (3GPP TS 23.038), each character one place. */
    private const BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** The extension table: each of these takes two places (an escape and the character). */
    private const EXTENSION = "\f^{}\\[~]|€";

    /** @return array{encoding: 'gsm7'|'ucs2', length: int, segments: int} the alphabet, the length in its own unit and the number of parts */
    public static function describe(string $text): array
    {
        if (self::isGsm7($text)) {
            $length = 0;
            foreach (mb_str_split($text) as $char) {
                $length += mb_strpos(self::EXTENSION, $char) !== false ? 2 : 1;
            }

            return ['encoding' => 'gsm7', 'length' => $length, 'segments' => $length <= 160 ? 1 : (int) ceil($length / 153)];
        }
        $length = intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);   // UTF-16 code units: a character outside the basic plane takes two

        return ['encoding' => 'ucs2', 'length' => $length, 'segments' => $length <= 70 ? 1 : (int) ceil($length / 67)];
    }

    public static function isGsm7(string $text): bool
    {
        foreach (mb_str_split($text) as $char) {
            if (mb_strpos(self::BASIC, $char) === false && mb_strpos(self::EXTENSION, $char) === false) {
                return false;
            }
        }

        return true;
    }

    /** 'gsm7' or 'ucs2'. $mode: auto (by the text) | unicode (always UCS-2, for a gateway that wants it). */
    public static function choose(string $text, string $mode = 'auto'): string
    {
        return $mode === 'unicode' || ! self::isGsm7($text) ? 'ucs2' : 'gsm7';
    }

    /** The text as UTF-16 big-endian in upper-case hexadecimal (what gateways call "UCS2 hex"). */
    public static function utf16Hex(string $text): string
    {
        return strtoupper(bin2hex((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
    }

    public static function utf16Base64(string $text): string
    {
        return base64_encode((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'));
    }
}
