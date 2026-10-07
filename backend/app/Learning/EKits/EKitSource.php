<?php

namespace App\Learning\EKits;

use RuntimeException;

/** Reads and checks the e-kit sources kept in backend/resources/ekits/kits.json (the guides and session plans are in content/e-kits). */
class EKitSource
{
    public static function path(): string
    {
        return (string) (config('tedc.ekits_path') ?: resource_path('ekits/kits.json'));
    }

    /** @return list<array<string, mixed>> */
    public static function all(?string $path = null): array
    {
        $path ??= self::path();
        if (! is_file($path)) {
            throw new RuntimeException("E-kit source not found: {$path}");
        }
        $kits = json_decode((string) file_get_contents($path), true);
        if (! is_array($kits)) {
            throw new RuntimeException('E-kit source is not valid JSON.');
        }
        foreach ($kits as $kit) {
            self::validate($kit);
        }

        return array_values($kits);
    }

    public static function find(string $code): ?array
    {
        foreach (self::all() as $kit) {
            if ($kit['code'] === $code) {
                return $kit;
            }
        }

        return null;
    }

    /** Every kit needs both languages everywhere, a knowledge check per chapter and a final question set. */
    public static function validate(array $kit): void
    {
        $need = static function (array $a, array $keys, string $where): void {
            foreach ($keys as $k) {
                if (! isset($a[$k]) || $a[$k] === '' || $a[$k] === []) {
                    throw new RuntimeException("E-kit {$where}: «{$k}» is missing.");
                }
            }
        };
        $need($kit, ['code', 'title_ar', 'title_en', 'objectives_ar', 'objectives_en', 'chapters', 'final'], $kit['code'] ?? '?');
        foreach ($kit['chapters'] as $i => $c) {
            $need($c, ['title_ar', 'title_en', 'body_ar', 'body_en', 'check'], "{$kit['code']} chapter ".($i + 1));
            foreach ($c['check'] as $q) {
                self::question($q, "{$kit['code']} chapter ".($i + 1), $need);
            }
        }
        foreach ($kit['final'] as $i => $q) {
            self::question($q, "{$kit['code']} final ".($i + 1), $need);
        }
        if (count($kit['objectives_ar']) !== count($kit['objectives_en'])) {
            throw new RuntimeException("E-kit {$kit['code']}: the Arabic and English objectives differ in number.");
        }
    }

    private static function question(array $q, string $where, callable $need): void
    {
        $need($q, ['stem_ar', 'stem_en', 'options'], $where);
        if (count($q['options']) < 2 || ! isset($q['correct'], $q['options'][$q['correct']])) {
            throw new RuntimeException("E-kit {$where}: a question needs at least two options and a valid correct answer.");
        }
        foreach ($q['options'] as $o) {
            $need($o, ['ar', 'en'], $where);
        }
    }

    /** The plain text this portal's article lessons show: «# » heading, «- » bullets, blank-line paragraphs. */
    public static function plain(string $html): string
    {
        $html = preg_replace('~<h[1-6][^>]*>(.*?)</h[1-6]>~is', "\n\n# $1\n\n", $html) ?? $html;
        $html = preg_replace('~<li[^>]*>(.*?)</li>~is', "\n- $1", $html) ?? $html;
        $html = preg_replace('~</(p|ul|ol|blockquote)>~i', "\n\n", $html) ?? $html;
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("~\n{3,}~", "\n\n", preg_replace('~[ \t]+\n~', "\n", $text) ?? $text));
    }
}
