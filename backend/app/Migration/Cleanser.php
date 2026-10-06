<?php

namespace App\Migration;

use Carbon\Carbon;
use Throwable;

/** Cleansing of the values people type into spreadsheets: Arabic text, digits, dates, e-mails, phones, gender. */
class Cleanser
{
    private const INDIC = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    public static function digits(string $s): string
    {
        return strtr($s, self::INDIC);
    }

    /** Spaces collapsed, tatweel and diacritics removed, alef and yeh variants unified — so "أحمد" and "احمد" match. */
    public static function arabic(?string $s): string
    {
        $s = trim((string) $s);
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s) ?? $s;
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و']);

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** A name as it is stored: trimmed and tidy, but not rewritten. */
    public static function name(?string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $s) ?? '');
    }

    public static function code(?string $s): string
    {
        return strtoupper(trim(self::digits((string) $s)));
    }

    public static function email(?string $s): ?string
    {
        $e = strtolower(trim((string) $s));

        return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    public static function phone(?string $s): ?string
    {
        $d = preg_replace('/[^\d+]/', '', self::digits((string) $s)) ?? '';

        return strlen(preg_replace('/\D/', '', $d) ?? '') >= 8 ? $d : null;
    }

    public static function gender(?string $s): ?string
    {
        return match (mb_strtolower(trim((string) $s))) {
            'male', 'm', 'ذكر', 'ذكور', 'رجل' => 'male', 'female', 'f', 'أنثى', 'انثى', 'إناث', 'اناث', 'امرأة' => 'female', default => null,
        };
    }

    /** Dates in the formats spreadsheets produce (2024-03-01, 01/03/2024, 1-3-2024, an Excel day number). Day first when it is ambiguous. */
    public static function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();   // Excel serial
        }
        $s = trim(self::digits((string) $v));
        try {
            if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m)) {
                return Carbon::createStrict((int) $m[1], (int) $m[2], (int) $m[3])->toDateString();
            }
            if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/', $s, $m)) {
                return Carbon::createStrict((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public static function number(mixed $v): ?float
    {
        $s = str_replace(['٫', ','], '.', self::digits(trim((string) $v)));

        return $s !== '' && is_numeric($s) ? (float) $s : null;
    }
}
