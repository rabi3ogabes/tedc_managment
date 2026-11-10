<?php

namespace App\Support;

/** Accepts only plain-drawing SVGs: no scripts, event handlers, embedded documents or external references. */
class SvgGuard
{
    public static function isSafe(string $svg): bool
    {
        if (! preg_match('/<svg[\s>]/i', $svg) || strlen($svg) > 2_000_000) {
            return false;
        }

        return ! preg_match('/<\s*(script|foreignObject|iframe|object|embed|audio|video|animate|set|use\b[^>]*(href|xlink:href)\s*=\s*["\'](?!#))/i', $svg)
            && ! preg_match('/\son[a-z]+\s*=/i', $svg)
            && ! preg_match('/(javascript:|data:text\/html|<!ENTITY|<!DOCTYPE[^>]*\[)/i', $svg)
            && ! preg_match('/(href|src)\s*=\s*["\']\s*(https?:|\/\/)/i', $svg);
    }
}
