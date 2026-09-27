<?php

namespace App\Services\Eligibility;

use JsonSerializable;

final class EligibilityResult implements JsonSerializable
{
    /** @param array<int, array{key: string, field: string, passed: bool, mandatory: bool, message: string}> $checks */
    public function __construct(public readonly bool $eligible, public readonly array $checks) {}

    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => ! $c['passed'] && $c['mandatory']));
    }

    public function warnings(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => ! $c['passed'] && ! $c['mandatory']));
    }

    public function summary(): string
    {
        if ($this->eligible) {
            return __('eligibility.summary_eligible');
        }

        return __('eligibility.summary_not_eligible', [
            'reasons' => implode(app()->getLocale() === 'ar' ? '، ' : '; ', array_column($this->failures(), 'message')),
        ]);
    }

    public function jsonSerialize(): array
    {
        return [
            'eligible' => $this->eligible,
            'status' => $this->eligible ? 'eligible' : 'not_eligible',
            'label' => __($this->eligible ? 'eligibility.eligible' : 'eligibility.not_eligible'),
            'color' => $this->eligible ? 'green' : 'red',
            'summary' => $this->summary(),
            'checks' => $this->checks,
            'warnings' => $this->warnings(),
            'evaluated_at' => now()->toIso8601String(),
        ];
    }
}
