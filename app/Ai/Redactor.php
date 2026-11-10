<?php

namespace App\Ai;

/**
 * Takes personal identifiers out of text before it is sent to a model and puts them back in the answer.
 * Emails, phone numbers, national ID numbers, other long numbers and the names given by the caller become tokens such as [EMAIL_1].
 */
class Redactor
{
    /** @var array<string, string> token => original */
    private array $map = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @param  list<string>  $names  names to hide (the person's own, their manager's…) */
    public function __construct(private readonly array $names = []) {}

    public function redact(string $text): string
    {
        $patterns = [
            'EMAIL' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
            'ID' => '/(?<![\d])[23]\d{10}(?![\d])/u',                                        // Qatar ID: 11 digits starting with 2 or 3
            'PHONE' => '/(?<![\d])(?:\+?974[\s\-]?)?[3567]\d{3}[\s\-]?\d{4}(?![\d])/u',
            'NUMBER' => '/(?<![\d])\d{9,}(?![\d])/u',
        ];
        foreach ($patterns as $kind => $re) {
            $text = (string) preg_replace_callback($re, fn ($m) => $this->token($kind, $m[0]), $text);
        }
        foreach ($this->names as $name) {
            $name = trim($name);
            if (mb_strlen($name) < 3) {
                continue;
            }
            $text = (string) preg_replace_callback('/'.preg_quote($name, '/').'/iu', fn ($m) => $this->token('NAME', $m[0]), $text);
            foreach (preg_split('/[\s\-]+/u', $name) ?: [] as $part) {   // first or family name on its own
                if (mb_strlen($part) >= 3) {
                    $text = (string) preg_replace_callback('/(?<![\p{L}\p{N}])'.preg_quote($part, '/').'(?![\p{L}\p{N}])/iu', fn ($m) => $this->token('NAME', $m[0]), $text);
                }
            }
        }

        return $text;
    }

    public function restore(string $text): string
    {
        return strtr($text, $this->map);
    }

    public function count(): int
    {
        return count($this->map);
    }

    private function token(string $kind, string $original): string
    {
        $existing = array_search($original, $this->map, true);
        if ($existing !== false) {
            return $existing;
        }
        $this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;
        $token = "[{$kind}_{$this->counters[$kind]}]";
        $this->map[$token] = $original;

        return $token;
    }
}
