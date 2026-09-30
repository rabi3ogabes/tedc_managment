<?php

namespace App\Services\Kits;

/** Keeps generated / uploaded SVG illustrations to drawing primitives - no script, no external loading. */
final class SvgSanitizer
{
    public static function clean(string $svg): string
    {
        $svg = preg_replace('#<\?xml.*?\?>#s', '', $svg) ?? $svg;
        $svg = preg_replace('#<!DOCTYPE.*?>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<(script|foreignObject|iframe|object|embed|style)\b.*?</\1>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<(script|foreignObject|iframe|object|embed)\b[^>]*/?>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg) ?? $svg;
        // Only in-document references (#id) are allowed for href / xlink:href / url().
        $svg = preg_replace('#\s(?:xlink:)?href\s*=\s*("(?!\#)[^"]*"|\'(?!\#)[^\']*\')#i', '', $svg) ?? $svg;
        $svg = preg_replace('#url\(\s*(?![\'"]?\#)[^)]*\)#i', 'none', $svg) ?? $svg;
        $svg = trim($svg);

        if (! preg_match('#^<svg\b#i', $svg)) {
            $pos = stripos($svg, '<svg');
            $svg = $pos === false ? self::fallback() : substr($svg, $pos);
        }
        if (! preg_match('#xmlns=#', $svg)) {
            $svg = preg_replace('#<svg\b#i', '<svg xmlns="http://www.w3.org/2000/svg"', $svg, 1) ?? $svg;
        }

        return $svg;
    }

    private static function fallback(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1280 720"><rect width="1280" height="720" fill="#F3EFE7"/></svg>';
    }
}
