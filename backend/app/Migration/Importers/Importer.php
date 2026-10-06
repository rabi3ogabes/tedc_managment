<?php

namespace App\Migration\Importers;

/** One kind of legacy data: which columns it has, how a row is cleansed and checked, and how it is written (and undone). */
abstract class Importer
{
    abstract public function kind(): string;

    /** @return array<string, array{label: array{ar: string, en: string}, required?: bool, aliases?: list<string>, example?: string}> */
    abstract public function fields(): array;

    /** The business key that identifies the record (an employee number, a program code); null when it cannot be worked out. @param  array<string, mixed>  $m */
    abstract public function key(array $m): ?string;

    /**
     * Cleansed values and the problems found.
     *
     * @param  array<string, mixed>  $m
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    abstract public function clean(array $m): array;

    /** True when applying the row would update something that exists. @param  array<string, mixed>  $c */
    abstract public function exists(array $c): bool;

    /**
     * Writes the row.
     *
     * @param  array<string, mixed>  $c
     * @return array{action: string, id: string, before: ?array<string, mixed>, created: list<array{table: string, id: string}>}
     */
    abstract public function apply(array $c): array;

    /** @param  array<string, mixed>  $m  @param  list<string>  $required @return list<string> */
    protected function missing(array $m, array $required): array
    {
        return array_values(array_map(fn ($f) => "missing:{$f}", array_filter($required, fn ($f) => ! isset($m[$f]) || $m[$f] === '' || $m[$f] === null)));
    }

    protected function f(string $ar, string $en, bool $required = false, array $aliases = [], ?string $example = null): array
    {
        return ['label' => ['ar' => $ar, 'en' => $en], 'required' => $required, 'aliases' => $aliases, 'example' => $example];
    }
}
