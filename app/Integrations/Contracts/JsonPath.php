<?php

namespace App\Integrations\Contracts;

/**
 * The small, safe subset of JSONPath contracts use to pick values out of a response: `$`, `.name`, `[0]`, `['quoted key']`, `[*]` and one
 * equality test, `[?(@.key=='text')]`, that keeps the elements of a list whose key holds that text (the activity of one kind in a list of
 * activities). No recursion, other filters, slices, unions or scripts: a path is a fixed route through the data and never runs anything.
 */
final class JsonPath
{
    /** @return list<array{0: string, 1: string|int|list<string>|null}>|null the steps, or null when the path is not in the subset */
    public static function parse(string $path): ?array
    {
        if ($path === '' || $path[0] !== '$') {
            return null;
        }
        $steps = [];
        $i = 1;
        $n = strlen($path);
        while ($i < $n) {
            if ($path[$i] === '.') {
                if (! preg_match('/\G\.([A-Za-z_][A-Za-z0-9_]*)/', $path, $m, 0, $i)) {
                    return null;   // "..", a trailing dot, a name that is not plain
                }
                $steps[] = ['key', $m[1]];
                $i += strlen($m[0]);
            } elseif ($path[$i] === '[') {
                if (preg_match('/\G\[(\d+)\]/', $path, $m, 0, $i)) {
                    $steps[] = ['index', (int) $m[1]];
                } elseif (preg_match('/\G\[\*\]/', $path, $m, 0, $i)) {
                    $steps[] = ['wild', null];
                } elseif (preg_match('/\G\[\?\(@\.([A-Za-z_][A-Za-z0-9_]*)==(?:\'([^\'\\\\]*)\'|"([^"\\\\]*)")\)\]/', $path, $m, 0, $i)) {
                    $steps[] = ['filter', [$m[1], ($m[3] ?? '') !== '' ? $m[3] : $m[2]]];
                } elseif (preg_match('/\G\[(?:\'([^\'\\\\]*)\'|"([^"\\\\]*)")\]/', $path, $m, 0, $i)) {
                    $steps[] = ['key', $m[2] ?? $m[1]];
                } else {
                    return null;   // any other filter, a slice, a union, a script, a bare word
                }
                $i += strlen($m[0]);
            } else {
                return null;
            }
        }

        return $steps;
    }

    public static function valid(string $path): bool
    {
        return self::parse($path) !== null;
    }

    /** Every value the path reaches (a path without a wildcard reaches at most one; null and missing values are left out). @return list<mixed> */
    public static function all(mixed $data, string $path): array
    {
        $steps = self::parse($path);
        if ($steps === null) {
            return [];
        }
        $current = [$data];
        foreach ($steps as [$kind, $arg]) {
            $next = [];
            foreach ($current as $node) {
                if (! is_array($node)) {
                    continue;
                }
                if ($kind === 'wild') {
                    foreach ($node as $child) {
                        $next[] = $child;
                    }
                } elseif ($kind === 'filter') {
                    foreach ($node as $child) {
                        if (is_array($child) && array_key_exists($arg[0], $child) && is_scalar($child[$arg[0]]) && (string) $child[$arg[0]] === $arg[1]) {
                            $next[] = $child;
                        }
                    }
                } elseif (array_key_exists($arg, $node)) {
                    $next[] = $node[$arg];
                }
            }
            $current = $next;
        }

        return array_values(array_filter($current, fn ($v) => $v !== null));
    }

    /** The value at a path; a path with a wildcard gives the list. Missing is null, never an error. */
    public static function get(mixed $data, string $path): mixed
    {
        $steps = self::parse($path);
        if ($steps === null) {
            return null;
        }
        if (in_array('wild', array_column($steps, 0), true)) {
            return self::all($data, $path);
        }

        return self::all($data, $path)[0] ?? null;
    }
}
