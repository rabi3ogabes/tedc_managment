<?php

namespace App\Services\Saber;

/**
 * Arabic-aware fuzzy matching for the SABER+ imports: letters folded (hamza forms, alef maqsura, taa marbuta), diacritics and punctuation
 * dropped, then a Dice coefficient over character bigrams and a word-level score that pairs words fuzzily — so «تعليم مبكر» still finds
 * «لمعلمي التعليم المبكّر».
 */
class ArabicMatch
{
    public static function normalize(?string $s): string
    {
        $s = (string) $s;
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $s) ?? $s;
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه']);
        $s = preg_replace('~[()\[\]{}«»"\'’،,.:_\\\\/|-]~u', ' ', $s) ?? $s;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
    }

    public static function similarity(?string $a, ?string $b): float
    {
        $x = self::normalize($a);
        $y = self::normalize($b);
        if ($x === '' || $y === '') {
            return 0.0;
        }
        if ($x === $y) {
            return 1.0;
        }
        $ba = self::bigrams($x);
        $bb = self::bigrams($y);
        $inter = 0;
        foreach ($ba as $g => $n) {
            $inter += min($n, $bb[$g] ?? 0);
        }
        $total = array_sum($ba) + array_sum($bb);

        return $total ? (2 * $inter) / $total : 0.0;
    }

    /** The best of character and word similarity, against the name and the name without bracketed qualifiers. */
    public static function nameScore(string $candidate, string $name): float
    {
        $bare = preg_replace('/[(\[{][^)\]}]*[)\]}]/u', ' ', $name) ?? $name;

        return max(self::similarity($candidate, $name), self::similarity($candidate, $bare), self::words($candidate, $name), self::words($candidate, $bare));
    }

    private static function words(string $a, string $b): float
    {
        $wa = array_values(array_filter(explode(' ', self::normalize($a))));
        $wb = array_values(array_filter(explode(' ', self::normalize($b))));
        if (! $wa || ! $wb) {
            return 0.0;
        }
        $used = [];
        $common = 0;
        foreach ($wa as $w) {
            $best = -1;
            $score = 0.0;
            foreach ($wb as $i => $v) {
                if (isset($used[$i])) {
                    continue;
                }
                $s = $w === $v ? 1.0 : self::similarity($w, $v);
                if ($s > $score) {
                    [$score, $best] = [$s, $i];
                }
            }
            if ($best >= 0 && $score >= 0.7) {
                $used[$best] = true;
                $common++;
            }
        }

        return (2 * $common) / (count($wa) + count($wb));
    }

    /** @return array<string, int> */
    private static function bigrams(string $s): array
    {
        $chars = mb_str_split($s);
        $out = [];
        for ($i = 0; $i < count($chars) - 1; $i++) {
            $g = $chars[$i].$chars[$i + 1];
            $out[$g] = ($out[$g] ?? 0) + 1;
        }

        return $out;
    }
}
