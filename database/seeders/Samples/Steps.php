<?php

namespace Database\Seeders\Samples;

use Throwable;

/** Runs the parts of a sample section one by one: a part that cannot be built here is reported, and the others still run. */
trait Steps
{
    /** @var list<string> */
    public array $problems = [];

    protected function step(string $name, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->problems[] = static::class.'::'.$name.': '.mb_substr($e->getMessage(), 0, 200);
        }
    }
}
