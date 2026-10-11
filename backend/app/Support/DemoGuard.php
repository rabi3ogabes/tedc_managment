<?php

namespace App\Support;

/**
 * Demo data (accounts with a shared password, sample programs, scenarios) never appears on a live system. Outside
 * production it is always allowed; in production it never is. A demonstration deployment runs with APP_ENV=staging
 * (or demo), not production. The old TEDC_ALLOW_DEMO_IN_PRODUCTION switch is ignored on purpose: since 11 Oct 2026 the
 * live site is preparing for real data.
 */
class DemoGuard
{
    public static function allowed(): bool
    {
        return ! app()->environment('production');
    }
}
