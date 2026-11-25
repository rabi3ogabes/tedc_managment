<?php

namespace App\Services\Assessment;

/** Text comparison that treats Arabic spelling variants as the same word: diacritics, tatweel, alef / yaa / taa-marbuta forms. */
class ArabicText
{
    public static function normalize(string $text, bool $caseSensitive = false): string
    {
        $t = trim($text);
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t) ?? $t;          // tashkeel and tatweel
        $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $t = strtr($t, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $t = preg_replace('/[\p{P}\p{S}]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t);

        return $caseSensitive ? $t : mb_strtolower($t);
    }

    /** True when the answer matches any accepted answer after normalisation. */
    public static function matches(string $answer, array $accepted, bool $caseSensitive = false): bool
    {
        $a = self::normalize($answer, $caseSensitive);
        foreach ($accepted as $ok) {
            if ($a !== '' && $a === self::normalize((string) $ok, $caseSensitive)) {
                return true;
            }
        }

        return false;
    }
}
