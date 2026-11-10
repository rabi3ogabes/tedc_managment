<?php

namespace App\Integrations\Contracts;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * The placeholder language of contracts and mappings: `{{ path|filter:arg|filter }}` and nothing else. It is data, not code: a path is
 * dotted names and numbers, a filter is one of a fixed list, there are no expressions or function calls, and the context a template
 * reads never holds a secret (the keys of a setting that is a secret are refused when a contract is saved). A value is escaped for
 * where it lands: inside a JSON string, in a URL, in a header (no line breaks) or as is.
 */
final class Template
{
    private const PLACEHOLDER = '/\{\{\s*([^{}]*?)\s*\}\}/';

    private const PATH = '/^[A-Za-z_][A-Za-z0-9_]*(\.([A-Za-z_][A-Za-z0-9_]*|\d+))*$/';

    /**
     * Filters whose output is already safe where it lands, so the target's own escaping is skipped. `raw` is the one that skips it on
     * purpose, which is why it is only allowed on the system's own settings (a path the administrator sets), never on a request value.
     */
    private const SELF_ESCAPING = ['json', 'urlencode', 'raw'];

    private const FILTERS = ['json', 'urlencode', 'upper', 'lower', 'trim', 'int', 'float', 'bool', 'default', 'date', 'amount', 'minor', 'hex', 'base64', 'utf16hex', 'sha256', 'map', 'raw'];

    /** Fills a string template. $escape: raw | json (inside a JSON string) | url | header. @param  array<string, mixed>  $context */
    public static function render(string $template, array $context, string $escape = 'raw'): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $m) use ($context, $escape) {
            [$value, $filters] = self::evaluate($m[1], $context);
            $text = self::text($value);

            return in_array(end($filters) ?: '', self::SELF_ESCAPING, true) ? $text : self::escape($text, $escape);
        }, $template);
    }

    /**
     * Fills a structured value (a request body, a query, a headers map). A string that is exactly one placeholder keeps the value's own
     * type (a list stays a list, a number a number); a key ending in "?" is left out when its value is empty; `{"$each": "rows",
     * "as": "r", "template": {...}}` builds a list from a list in the context.
     *
     * @param  array<string, mixed>  $context
     */
    public static function renderValue(mixed $value, array $context): mixed
    {
        if (is_string($value)) {
            if (preg_match('/^\s*\{\{\s*([^{}]*?)\s*\}\}\s*$/', $value, $m)) {
                return self::evaluate($m[1], $context)[0];
            }

            return self::render($value, $context);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_key_exists('$each', $value)) {
            $rows = self::lookup($context, (string) $value['$each']);
            $as = (string) ($value['as'] ?? 'item');
            $out = [];
            foreach (is_array($rows) ? array_values($rows) : [] as $i => $row) {
                $out[] = self::renderValue($value['template'] ?? [], $context + [$as => $row, 'index' => $i]);
            }

            return $out;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $optional = is_string($k) && str_ends_with($k, '?');
            $rendered = self::renderValue($v, $context);
            if ($optional && ($rendered === null || $rendered === '')) {
                continue;
            }
            $out[$optional ? substr($k, 0, -1) : $k] = $rendered;
        }

        return $out;
    }

    /**
     * What is wrong with a template (empty when it is fine).
     *
     * @param  list<string>  $secretSettings  settings that hold secrets: their names may not be read
     * @param  list<string>|null  $knownSettings  the settings the system has (a name outside it is a typo); null: not checked
     * @return list<string>
     */
    public static function problems(string $template, array $secretSettings = [], ?array $knownSettings = null): array
    {
        $problems = [];
        $stripped = preg_replace(self::PLACEHOLDER, '', $template) ?? $template;
        if (str_contains($stripped, '{{') || str_contains($stripped, '}}')) {
            $problems[] = 'unbalanced braces in "'.mb_substr($template, 0, 60).'"';
        }
        preg_match_all(self::PLACEHOLDER, $template, $found);
        foreach ($found[1] as $expression) {
            $problems = array_merge($problems, self::expressionProblems($expression, $secretSettings, $knownSettings));
        }

        return $problems;
    }

    /** The problems of every template inside a structured value. @param  list<string>  $secretSettings @param  list<string>|null  $knownSettings @return list<string> */
    public static function problemsIn(mixed $value, array $secretSettings = [], ?array $knownSettings = null): array
    {
        if (is_string($value)) {
            return self::problems($value, $secretSettings, $knownSettings);
        }
        if (! is_array($value)) {
            return [];
        }
        $problems = [];
        foreach ($value as $k => $v) {
            if ($k === '$each' && is_string($v) && ! preg_match(self::PATH, $v)) {
                $problems[] = '$each must name a list by a plain path: "'.mb_substr($v, 0, 40).'"';
            }
            if ($k === '$each' || $k === 'as') {
                continue;
            }
            $problems = array_merge($problems, self::problemsIn($v, $secretSettings, $knownSettings));
        }

        return $problems;
    }

    /** The paths a template reads, once each, in order. @return list<string> */
    public static function placeholders(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $found);

        return array_values(array_unique(array_map(fn ($e) => trim(explode('|', $e)[0]), $found[1])));
    }

    // ---- internals ------------------------------------------------------------------------------------

    /** @return list<string> */
    private static function expressionProblems(string $expression, array $secretSettings, ?array $knownSettings): array
    {
        $parts = explode('|', $expression);
        $path = trim(array_shift($parts));
        if (! preg_match(self::PATH, $path)) {
            return ['"'.mb_substr($expression, 0, 60).'" is not a plain name (letters, digits, dots)'];
        }
        $problems = [];
        $segments = explode('.', $path);
        if ($segments[0] === 'secrets' || ($segments[0] === 'settings' && in_array($segments[1] ?? '', $secretSettings, true))) {
            $problems[] = 'a secret cannot be used in a template: '.$path;
        } elseif ($segments[0] === 'settings' && $knownSettings !== null && ! in_array($segments[1] ?? '', $knownSettings, true)) {
            $problems[] = 'this system has no setting "'.mb_substr($segments[1] ?? '', 0, 40).'" (a mistyped name would silently use its default)';
        }
        foreach ($parts as $i => $part) {
            $name = trim(explode(':', $part, 2)[0]);
            if (! in_array($name, self::FILTERS, true)) {
                $problems[] = 'unknown filter "'.mb_substr($name, 0, 30).'"';
            } elseif ($name === 'raw' && ($segments[0] !== 'settings' || $i !== array_key_last($parts))) {
                $problems[] = '"raw" is only for a setting of the system and must be the last filter: '.$path;
            } elseif ($name === 'map' && ! preg_match('/^[^=,]+=[^=,]*(,[^=,]+=[^=,]*)*$/', trim(explode(':', $part, 2)[1] ?? ''))) {
                $problems[] = '"map" needs pairs of value=word, such as map:ar=ARA,en=ENG';
            }
        }

        return $problems;
    }

    /** @return array{0: mixed, 1: list<string>} the value after its filters, and the filters' names */
    private static function evaluate(string $expression, array $context): array
    {
        $parts = explode('|', $expression);
        $path = trim(array_shift($parts));
        if (! preg_match(self::PATH, $path)) {
            throw new ContractException('bad_template', 'The template names "'.mb_substr($path, 0, 40).'", which is not a plain name.');
        }
        $value = self::lookup($context, $path);
        $names = [];
        foreach ($parts as $part) {
            [$name, $arg] = array_pad(explode(':', trim($part), 2), 2, null);
            $name = trim((string) $name);
            if (! in_array($name, self::FILTERS, true)) {
                throw new ContractException('bad_template', 'The template uses a filter that does not exist: "'.mb_substr($name, 0, 30).'".');
            }
            if ($name === 'raw' && ! str_starts_with($path, 'settings.')) {
                throw new ContractException('bad_template', 'The template leaves "'.mb_substr($path, 0, 40).'" unescaped, which only a setting of the system may be.');
            }
            $value = self::filter($name, $value, $arg);
            $names[] = $name;
        }

        return [$value, $names];
    }

    private static function lookup(array $context, string $path): mixed
    {
        $node = $context;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private static function filter(string $name, mixed $v, ?string $arg): mixed
    {
        $text = fn () => self::text($v);

        return match ($name) {
            'json' => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'urlencode' => rawurlencode($text()),
            'upper' => mb_strtoupper($text()),
            'lower' => mb_strtolower($text()),
            'trim' => trim($text()),
            'int' => is_numeric($v) ? (int) $v : 0,
            'float' => is_numeric($v) ? (float) $v : 0.0,
            'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'default' => ($v === null || $v === '') ? (string) $arg : $v,
            'date' => self::date($v, (string) ($arg ?: 'Y-m-d')),
            'amount' => number_format((float) $v, max(0, min(6, (int) $arg)), '.', ''),
            'minor' => (string) (int) round((float) $v * (10 ** max(0, min(6, (int) $arg)))),
            'hex' => bin2hex($text()),
            'base64' => base64_encode($text()),
            'utf16hex' => strtoupper(bin2hex((string) mb_convert_encoding($text(), 'UTF-16BE', 'UTF-8'))),
            'sha256' => hash('sha256', $text()),
            'map' => self::mapped($text(), (string) $arg),
            'raw' => $v,
        };
    }

    /** A value looked up in a table written inline ("ar=ARA,en=ENG"); a value the table does not know stays as it is. */
    private static function mapped(string $value, string $table): string
    {
        foreach (explode(',', $table) as $pair) {
            [$from, $to] = array_pad(explode('=', $pair, 2), 2, null);
            if ($to !== null && trim($from) === $value) {
                return trim($to);
            }
        }

        return $value;
    }

    private static function date(mixed $v, string $format): string
    {
        try {
            return $v === null || $v === '' ? '' : Carbon::parse((string) $v)->format($format);
        } catch (Throwable) {
            return '';
        }
    }

    private static function text(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'true' : 'false',
            is_array($v) => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $v,
        };
    }

    private static function escape(string $text, string $target): string
    {
        return match ($target) {
            'json' => substr((string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1),
            'url' => rawurlencode($text),
            'header' => str_replace(["\r", "\n"], '', $text),
            default => $text,
        };
    }
}
