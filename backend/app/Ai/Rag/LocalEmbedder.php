<?php

namespace App\Ai\Rag;

use App\Services\Assessment\ArabicText;

/**
 * A vector for a piece of text that is computed here, in the application: no text leaves the server and no external model is needed.
 * Feature hashing of normalised words (Arabic spellings folded, light prefix stripping) and word pairs into 256 dimensions, L2-normalised,
 * so cosine similarity is a dot product. Good for finding the lesson or page that talks about the same things as a question;
 * an embeddings model (for example Azure OpenAI in Qatar) can replace it later without changing the store.
 */
class LocalEmbedder
{
    public const DIM = 256;

    /** @var list<string> */
    private const STOP = ['في', 'من', 'على', 'الى', 'عن', 'ما', 'هل', 'هو', 'هي', 'ان', 'او', 'ثم', 'كان', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'مع', 'the', 'a', 'an', 'of', 'to', 'in', 'is', 'are', 'and', 'or', 'for', 'on', 'what', 'how', 'do', 'does', 'did', 'was', 'were', 'have', 'has', 'i', 'my', 'me', 'can', 'it', 'this', 'that', 'with', 'be', 'when', 'where', 'which', 'who', 'about'];

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $words = preg_split('/\s+/u', ArabicText::normalize($text)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 2 || in_array($w, self::STOP, true)) {
                continue;
            }
            if (preg_match('/^[\p{Arabic}]+$/u', $w) && mb_strlen($w) > 4) {      // light stemming: the article and common prefixes
                $w = (string) preg_replace('/^(ال|وال|بال|لل|فال|كال)/u', '', $w);
            } elseif (preg_match('/^[a-z]+$/', $w) && strlen($w) > 4) {
                $w = (string) preg_replace('/(ing|ed|es|s)$/', '', $w);
            }
            $out[] = $w;
        }

        return $out;
    }

    /** @return list<float> */
    public function embed(string $text): array
    {
        $v = array_fill(0, self::DIM, 0.0);
        $tokens = self::tokens($text);
        $prev = null;
        foreach ($tokens as $t) {
            $this->add($v, $t, 1.0);
            if ($prev !== null) {
                $this->add($v, $prev.' '.$t, 0.5);
            }
            $prev = $t;
        }
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));
        if ($norm > 0) {
            $v = array_map(fn ($x) => round($x / $norm, 5), $v);
        }

        return array_values($v);
    }

    /** @param  list<float>  $a @param  list<float>  $b */
    public static function cosine(array $a, array $b): float
    {
        $s = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $s += $a[$i] * $b[$i];
        }

        return $s;
    }

    /** @param  array<int, float>  $v */
    private function add(array &$v, string $token, float $weight): void
    {
        $h = crc32($token);
        $idx = $h % self::DIM;
        $sign = (crc32('s'.$token) & 1) ? 1.0 : -1.0;
        $v[$idx] += $sign * $weight;
    }
}
