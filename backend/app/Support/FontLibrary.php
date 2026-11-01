<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The font library of the Brand Studio (docs/FONTS.md): typefaces the administrator uploads, each a family with one file per weight
 * and format. The website uses the web formats (WOFF2 first), the mobile app the TrueType / OpenType files (Flutter cannot read WOFF).
 * Licensed fonts such as the Ministry's Lusail are uploaded here instead of being committed to the repository.
 */
class FontLibrary
{
    public const FORMATS = ['woff2', 'woff', 'otf', 'ttf'];

    public const MAX_FAMILIES = 20;

    public const MAX_FILES = 24;

    /**
     * A clean copy of a library as sent by the dashboard: known formats and weights only, one file per weight + style + format,
     * a safe family name, and no family twice.
     *
     * @param  array<int, mixed>  $list
     * @return list<array{id: string, family: string, files: list<array{weight: int, style: string, format: string, url: string}>}>
     */
    public static function clean(array $list): array
    {
        $out = [];
        $seen = [];
        foreach (array_slice($list, 0, self::MAX_FAMILIES) as $f) {
            if (! is_array($f)) {
                continue;
            }
            $family = self::family((string) ($f['family'] ?? ''));
            if ($family === '' || isset($seen[mb_strtolower($family)])) {
                continue;
            }
            $files = [];
            foreach (array_slice((array) ($f['files'] ?? []), 0, self::MAX_FILES) as $file) {
                if (! is_array($file) || ! in_array($file['format'] ?? null, self::FORMATS, true) || ! self::safeUrl($file['url'] ?? null)) {
                    continue;
                }
                $weight = (int) ($file['weight'] ?? 400);
                $weight = max(100, min(900, (int) round($weight / 100) * 100));
                $style = ($file['style'] ?? 'normal') === 'italic' ? 'italic' : 'normal';
                $files["{$weight}|{$style}|{$file['format']}"] = ['weight' => $weight, 'style' => $style, 'format' => $file['format'], 'url' => (string) $file['url']];
            }
            if (! $files) {
                continue;
            }
            ksort($files, SORT_NATURAL);
            $seen[mb_strtolower($family)] = true;
            $id = preg_replace('/[^a-z0-9_-]/i', '', (string) ($f['id'] ?? '')) ?: Str::slug($family) ?: Str::random(8);
            $out[] = ['id' => Str::limit($id, 40, ''), 'family' => $family, 'files' => array_values($files)];
        }

        return $out;
    }

    /** The files of a family the mobile app can register (TrueType / OpenType, one per weight + style), or an empty list. */
    public static function appFiles(array $library, ?string $family): array
    {
        if (! $family) {
            return [];
        }
        foreach ($library as $f) {
            if (mb_strtolower($f['family'] ?? '') !== mb_strtolower($family)) {
                continue;
            }
            $best = [];
            foreach ($f['files'] ?? [] as $file) {
                if (! in_array($file['format'], ['otf', 'ttf'], true)) {
                    continue;
                }
                $key = $file['weight'].'|'.$file['style'];
                $best[$key] ??= ['weight' => $file['weight'], 'style' => $file['style'], 'url' => $file['url']];
            }

            return array_values($best);
        }

        return [];
    }

    /** A family name that is safe in a CSS font stack and a Flutter family name. */
    public static function family(string $name): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\pL\pN \-]/u', '', $name))), 60, '');
    }

    /** Only uploaded assets (an https address or a path on this site), never a script or data address. */
    private static function safeUrl(mixed $url): bool
    {
        return is_string($url) && strlen($url) <= 500 && (str_starts_with($url, 'https://') || str_starts_with($url, '/') && ! str_starts_with($url, '//') || app()->environment('local', 'testing') && str_starts_with($url, 'http://'));
    }
}
