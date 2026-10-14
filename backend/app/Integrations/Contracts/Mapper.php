<?php

namespace App\Integrations\Contracts;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns one record of a system into TEDC's fields from a mapping ({fields, tables}). A field is a JSONPath, a list of paths (the first
 * that has a value), a constant or a template over the record, then a list of transforms from a fixed set. Nothing in a mapping runs
 * code; a value that does not map is left out so the receiving code keeps what it has.
 */
final class Mapper
{
    private const TRANSFORMS = ['trim', 'upper', 'lower', 'int', 'float', 'bool', 'date', 'table', 'split', 'join', 'first', 'name_part'];

    /**
     * @param  array{fields: array<string, mixed>, tables?: array<string, array<string, mixed>>}  $entity
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function apply(array $entity, array $source): array
    {
        $out = [];
        foreach ((array) ($entity['fields'] ?? []) as $field => $spec) {
            $spec = is_string($spec) ? ['path' => $spec] : (array) $spec;
            $value = null;
            if (array_key_exists('const', $spec)) {
                $value = $spec['const'];
            } elseif (isset($spec['template'])) {
                $value = Template::render((string) $spec['template'], $source);
            } else {
                foreach (isset($spec['paths']) ? (array) $spec['paths'] : [$spec['path'] ?? null] as $path) {
                    $found = is_string($path) ? JsonPath::get($source, $path) : null;
                    if ($found !== null && $found !== '') {
                        $value = $found;
                        break;
                    }
                }
            }
            foreach ((array) ($spec['transform'] ?? []) as $transform) {
                if ($value === null || $value === '') {
                    break;
                }
                $value = self::transform((string) $transform, $value, (array) ($entity['tables'] ?? []));
            }
            if (($value === null || $value === '') && array_key_exists('default', $spec)) {
                $value = $spec['default'];
            }
            if ($value !== null && $value !== '') {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /** What is wrong with a mapping (empty when it is fine). @return list<string> */
    public static function problems(array $entity): array
    {
        if (! isset($entity['fields']) || ! is_array($entity['fields']) || $entity['fields'] === []) {
            return ['a mapping needs "fields"'];
        }
        $problems = [];
        $tables = $entity['tables'] ?? [];
        foreach ((array) $tables as $name => $table) {
            if (! is_array($table) || array_filter($table, fn ($v) => is_array($v)) !== []) {
                $problems[] = 'table "'.$name.'" must map codes to plain values';
            }
        }
        foreach ($entity['fields'] as $field => $spec) {
            $spec = is_string($spec) ? ['path' => $spec] : $spec;
            if (! is_array($spec)) {
                $problems[] = 'field "'.$field.'" must be a path or an object';

                continue;
            }
            $paths = isset($spec['paths']) ? (array) $spec['paths'] : (isset($spec['path']) ? [$spec['path']] : []);
            if ($paths === [] && ! array_key_exists('const', $spec) && ! isset($spec['template'])) {
                $problems[] = 'field "'.$field.'" needs a path, paths, a constant or a template';
            }
            foreach ($paths as $path) {
                if (! is_string($path) || ! JsonPath::valid($path)) {
                    $problems[] = 'field "'.$field.'" has a path outside the supported subset: '.(is_string($path) ? mb_substr($path, 0, 40) : 'not text');
                }
            }
            if (array_key_exists('const', $spec) && ! is_scalar($spec['const']) && $spec['const'] !== null) {
                $problems[] = 'field "'.$field.'": a constant must be a plain value';
            }
            if (isset($spec['template'])) {
                $problems = array_merge($problems, array_map(fn ($p) => 'field "'.$field.'": '.$p, Template::problems((string) $spec['template'])));
            }
            foreach ((array) ($spec['transform'] ?? []) as $transform) {
                $problems = array_merge($problems, array_map(fn ($p) => 'field "'.$field.'": '.$p, self::transformProblems((string) $transform, (array) $tables)));
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function transformProblems(string $transform, array $tables): array
    {
        [$name, $arg] = array_pad(explode(':', $transform, 2), 2, null);
        if (! in_array($name, self::TRANSFORMS, true)) {
            return ['unknown transform "'.mb_substr($name, 0, 30).'"'];
        }
        if ($name === 'table' && ! isset($tables[(string) $arg])) {
            return ['transform "table:'.mb_substr((string) $arg, 0, 30).'" names a table that is not defined'];
        }
        if ($name === 'date' && ! preg_match('/^[A-Za-z0-9 \/\-.:,]+>[A-Za-z0-9 \/\-.:,]+$/', (string) $arg)) {
            return ['transform "date" needs "from>to" formats, such as date:d/m/Y>Y-m-d'];
        }
        if ($name === 'name_part' && ! in_array($arg, ['ar', 'en'], true)) {
            return ['transform "name_part" is name_part:ar or name_part:en'];
        }

        return [];
    }

    private static function transform(string $transform, mixed $v, array $tables): mixed
    {
        [$name, $arg] = array_pad(explode(':', $transform, 2), 2, null);
        $text = is_scalar($v) ? (string) $v : '';

        return match ($name) {
            'trim' => trim($text),
            'upper' => mb_strtoupper($text),
            'lower' => mb_strtolower($text),
            'int' => is_numeric($v) ? (int) $v : null,
            'float' => is_numeric($v) ? (float) $v : null,
            'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'date' => self::date($text, (string) $arg),
            'table' => (array_key_exists($text, (array) ($tables[(string) $arg] ?? [])) ? $tables[(string) $arg][$text] : $v),
            'split' => array_values(array_filter(array_map('trim', explode((string) $arg, $text)), fn ($x) => $x !== '')),
            'join' => is_array($v) ? implode((string) $arg, $v) : $v,
            'first' => is_array($v) ? ($v[0] ?? null) : $v,
            'name_part' => self::namePart($text, (string) $arg),
            default => $v,
        };
    }

    /** "الاسم / Name": Arabic first, English second; a name without a slash is the same in both. */
    private static function namePart(string $text, string $lang): string
    {
        $parts = array_map('trim', explode('/', $text, 2));

        return count($parts) === 1 ? $parts[0] : ($lang === 'ar' ? $parts[0] : $parts[1]);
    }

    private static function date(string $text, string $formats): ?string
    {
        [$from, $to] = array_pad(explode('>', $formats, 2), 2, 'Y-m-d');
        try {
            return Carbon::createFromFormat('!'.$from, $text)->format($to);
        } catch (Throwable) {
            return null;
        }
    }
}
