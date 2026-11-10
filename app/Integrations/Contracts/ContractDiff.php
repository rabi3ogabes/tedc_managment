<?php

namespace App\Integrations\Contracts;

/** The difference between two versions of a contract or a mapping, path by path (what an administrator reviews before a rollback). */
final class ContractDiff
{
    private const SHOWN = 300;

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{path: string, change: 'added'|'removed'|'changed', before: mixed, after: mixed}>
     */
    public static function between(array $before, array $after): array
    {
        $a = self::flatten($before);
        $b = self::flatten($after);
        $paths = array_unique(array_merge(array_keys($a), array_keys($b)));
        usort($paths, fn ($x, $y) => strcmp((string) $x, (string) $y));

        $out = [];
        foreach ($paths as $path) {
            $path = (string) $path;
            $had = array_key_exists($path, $a);
            $has = array_key_exists($path, $b);
            if ($had && $has) {
                if ($a[$path] !== $b[$path]) {
                    $out[] = ['path' => $path, 'change' => 'changed', 'before' => self::cut($a[$path]), 'after' => self::cut($b[$path])];
                }
            } elseif ($had) {
                $out[] = ['path' => $path, 'change' => 'removed', 'before' => self::cut($a[$path]), 'after' => null];
            } else {
                $out[] = ['path' => $path, 'change' => 'added', 'before' => null, 'after' => self::cut($b[$path])];
            }
        }

        return $out;
    }

    /** Every leaf of a document by its dotted path; an empty list or object is a leaf of its own. @return array<string, mixed> */
    private static function flatten(array $doc, string $prefix = ''): array
    {
        $out = [];
        foreach ($doc as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value) && $value !== []) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    private static function cut(mixed $value): mixed
    {
        return is_string($value) && mb_strlen($value) > self::SHOWN ? mb_substr($value, 0, self::SHOWN).'…' : $value;
    }
}
