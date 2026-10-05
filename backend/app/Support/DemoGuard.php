<?php

namespace App\Support;

/**
 * Demo data (accounts with a shared password, sample programs, scenarios) must never appear on a live system by accident.
 * Outside production it is always allowed. In production it needs the explicit environment switch
 * TEDC_ALLOW_DEMO_IN_PRODUCTION=true, which only a demonstration deployment sets.
 */
class DemoGuard
{
    public static function allowed(): bool
    {
        return ! app()->environment('production') || (bool) config('tedc.demo.allow_in_production');
    }
}
