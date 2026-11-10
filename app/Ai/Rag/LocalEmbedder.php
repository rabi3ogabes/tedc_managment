<?php

namespace App\Ai\Rag;

use App\Services\Assessment\ArabicText;

/**
 * A vector for a piece of text that is computed here, in the application: no text leaves the server and no external model is needed.
 * Feature hashing into 1024 dimensions of normalised words (Arabic spellings folded; light stemming of the article, conjunctions,
 * plural and pronoun endings), their character trigrams (so حضوري and حضورك, or verify and verification, meet) and word pairs;
 * L2-normalised, so cosine similarity is a dot product. Good for finding the lesson or page that talks about the same things as a
 * question, and the fallback whenever an embeddings model (for example Azure OpenAI in Qatar) cannot be used.
 *
 * Changing how vectors are made changes ID, so vectors of an older version are never compared with new ones (tedc:deploy rebuilds them).
 */
class LocalEmbedder implements Embedder
{
    public const DIM = 1024;

    public const ID = 'local-hash-1024';

    /** Weight of a word's character trigrams together, and of a word pair, against the word itself (1). */
    private const GRAMS = 1.0;

    private const PAIRS = 0.3;

    /** @var list<string> */
    private const STOP = ['في', 'من', 'على', 'الى', 'عن', 'ما', 'هل', 'هو', 'هي', 'ان', 'او', 'ثم', 'كان', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'مع', 'كيف', 'ماذا', 'لماذا', 'متى', 'اين',
        'كم', 'اذا', 'لا', 'لم', 'لن', 'قد', 'كل', 'الا', 'حتى', 'عند', 'لي', 'يمكنني', 'يمكن', 'هناك', 'انا', 'نحن', 'انت', 'عندي',
        'the', 'a', 'an', 'of', 'to', 'in', 'is', 'are', 'and', 'or', 'for', 'on', 'what', 'how', 'do', 'does', 'did', 'was', 'were', 'have', 'has', 'i', 'my', 'me', 'can', 'it', 'this', 'that',
        'with', 'be', 'when', 'where', 'which', 'who', 'about', 'will', 'should', 'would', 'could', 'there', 'your', 'you', 'from', 'by', 'at', 'if', 'so', 'its', 'get', 'into'];

    public function id(): string
    {
        return self::ID;
    }

    public function dim(): int
    {
        return self::DIM;
    }

    public function embedMany(array $texts, bool $personal = false): array
    {
        return array_map(fn ($t) => $this->embed((string) $t), array_values($texts));
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $words = preg_split('/\s+/u', ArabicText::normalize($text)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 2 || in_array($w, self::STOP, true)) {
                continue;
            }
            if (preg_match('/^\p{Arabic}+$/u', $w)) {
                $w = self::stemArabic($w);
            } elseif (preg_match('/^[a-z]+$/', $w) && strlen($w) > 4) {
                $w = (string) preg_replace('/(ing|ed|es|s)$/', '', $w);
            }
            if (mb_strlen($w) >= 2 && ! in_array($w, self::STOP, true)) {
                $out[] = $w;
            }
        }

        return $out;
    }

    /** Light stemming (after Larkey's light10): one article or conjunction prefix and one plural or pronoun ending come off while three letters remain. */
    private static function stemArabic(string $w): string
    {
        foreach (['وال', 'بال', 'كال', 'فال', 'لل', 'ال'] as $p) {
            if (str_starts_with($w, $p) && mb_strlen($w) - mb_strlen($p) >= 3) {
                $w = mb_substr($w, mb_strlen($p));
                break;
            }
        }
        foreach (['هما', 'كما', 'ها', 'ان', 'ات', 'ون', 'ين', 'يه', 'كم', 'هم', 'نا', 'ه', 'ي', 'ك'] as $s) {
            if (str_ends_with($w, $s) && mb_strlen($w) - mb_strlen($s) >= 3) {
                return mb_substr($w, 0, -mb_strlen($s));
            }
        }

        return $w;
    }

    /** @return list<float> */
    public function embed(string $text): array
    {
        $v = array_fill(0, self::DIM, 0.0);
        $prev = null;
        foreach (self::tokens($text) as $t) {
            $this->add($v, $t, 1.0);
            if (mb_strlen($t) >= 3) {
                $padded = '<'.$t.'>';
                $n = mb_strlen($padded) - 2;
                for ($i = 0; $i < $n; $i++) {
                    $this->add($v, '#'.mb_substr($padded, $i, 3), self::GRAMS / $n);
                }
            }
            if ($prev !== null) {
                $this->add($v, $prev.' '.$t, self::PAIRS);
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
        $idx = crc32($token) % self::DIM;
        $sign = (crc32('s'.$token) & 1) ? 1.0 : -1.0;
        $v[$idx] += $sign * $weight;
    }
}
