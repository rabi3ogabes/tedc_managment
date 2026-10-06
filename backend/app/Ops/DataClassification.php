<?php

namespace App\Ops;

use Illuminate\Support\Facades\Schema;

/** Applies config/data_classification.php to the live schema and renders the register. */
class DataClassification
{
    /** @return array<string, array<string, string>> table => column => class */
    public function map(): array
    {
        $out = [];
        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            foreach (Schema::getColumnListing($name) as $col) {
                $out[$name][$col] = $this->classify($name, $col);
            }
        }
        ksort($out);

        return $out;
    }

    public function classify(string $table, string $column): string
    {
        if ($o = config("data_classification.overrides.{$table}.{$column}") ?? config('data_classification.overrides')["{$table}.{$column}"] ?? null) {
            return $o;
        }
        foreach (config('data_classification.columns') as [$class, $re]) {
            if (preg_match($re, $column)) {
                // A table-wide rule can only raise a column above Public, never lower a Restricted/Confidential column rule.
                $default = config("data_classification.tables.{$table}");

                return $class === 'public' && $default ? $default : $class;
            }
        }

        return config("data_classification.tables.{$table}") ?? 'internal';
    }

    /** @return list<string> columns of a table with the given class */
    public function columnsOf(string $table, string $class): array
    {
        return array_keys(array_filter($this->map()[$table] ?? [], fn ($c) => $c === $class));
    }

    public function markdown(): string
    {
        $classes = config('data_classification.classes');
        $encrypted = config('data_classification.app_encrypted');
        $map = $this->map();
        $count = array_count_values(array_merge([], ...array_values(array_map('array_values', $map))));
        $md = "# Data classification register\n\n";
        $md .= "> Generated from `config/data_classification.php` and the live schema by `php artisan tedc:data-classification --write`. Do not edit by hand: a test fails when this file and the schema disagree.\n\n";
        $md .= "**ملخص:** يصنّف هذا السجل كل جدول وعمود في المنصة إلى عام / داخلي / سري / مقيّد، مع الضابط الذي يحكم كل فئة. الحقول المقيّدة (كلمات المرور، الأسرار، الهوية الوطنية) إما مجزّأة أو مشفّرة على مستوى التطبيق ولا تُسجَّل ولا تُصدَّر.\n\n";
        $md .= "## Classes and controls\n\n| Class | الفئة | Control |\n|---|---|---|\n";
        foreach ($classes as $k => $c) {
            $md .= "| {$c['en']} | {$c['ar']} | {$c['control']} |\n";
        }
        $md .= "\n## Totals\n\n";
        foreach ($classes as $k => $c) {
            $md .= '- '.$c['en'].': '.($count[$k] ?? 0)." columns\n";
        }
        $md .= "\n## Application-level encryption\n\n";
        foreach ($encrypted as $e) {
            $md .= "- `{$e}`\n";
        }
        $md .= "\nOther Restricted columns hold password hashes, one-time or expiring tokens, or references to secrets kept in Key Vault; none is stored in clear text.\n\n## Register\n\n";
        foreach ($map as $table => $cols) {
            $md .= "### `{$table}`\n\n| Column | Class | Control |\n|---|---|---|\n";
            foreach ($cols as $col => $class) {
                $ctl = $class === 'restricted' ? (in_array("{$table}.{$col}", $encrypted, true) ? 'application-level encryption' : 'hash / secret / expiring token') : ($class === 'confidential' ? 'masked in exports' : '—');
                $md .= "| `{$col}` | {$classes[$class]['en']} | {$ctl} |\n";
            }
            $md .= "\n";
        }

        return $md;
    }
}
