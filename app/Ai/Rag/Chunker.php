<?php

namespace App\Ai\Rag;

/** Splits a long text into pieces of about 600 characters at paragraph and sentence boundaries, with a little overlap so an answer is not cut in half. */
class Chunker
{
    /** @return list<string> */
    public static function split(string $text, int $size = 600, int $overlap = 80): array
    {
        $text = trim(preg_replace("/[ \t]+/u", ' ', html_entity_decode(strip_tags(preg_replace('#</(p|div|li|h[1-6]|br)>#i', "\n", $text) ?? $text))) ?? '');
        if ($text === '') {
            return [];
        }
        $sentences = preg_split('/(?<=[\.\!\?؟۔\n])\s+/u', $text) ?: [$text];
        $chunks = [];
        $cur = '';
        foreach ($sentences as $s) {
            $s = trim($s);
            if ($s === '') {
                continue;
            }
            if ($cur !== '' && mb_strlen($cur) + mb_strlen($s) > $size) {
                $chunks[] = $cur;
                $cur = mb_substr($cur, max(0, mb_strlen($cur) - $overlap));
            }
            $cur = trim($cur.' '.$s);
        }
        if ($cur !== '') {
            $chunks[] = $cur;
        }

        return array_values(array_filter(array_map('trim', $chunks), fn ($c) => mb_strlen($c) > 20 || count($chunks) === 1));
    }
}
