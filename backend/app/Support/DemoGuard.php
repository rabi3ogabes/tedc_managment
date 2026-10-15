<?php

namespace App\Support;

/**
 * Demo data (accounts with a shared password, sample programs, scenarios) never appears on a live system. Outside
 * production it is always allowed; in production it never is. A demonstration deployment runs with APP_ENV=staging
 * (or demo), not production. The old TEDC_ALLOW_DEMO_IN_PRODUCTION switch is ignored on purpose: since 11 Oct 2026 the
 * live site is preparing for real data. The one exception is a deployment declared a demonstration site with
 * TEDC_DEMO_SITE=true in its hosting environment (see allowed()).
 */
class DemoGuard
{
    public static function allowed(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        // A demonstration site is a deliberate choice, made once in the hosting environment (never from a screen):
        // TEDC_DEMO_SITE=true says this production deployment shows sample data and the demo accounts.
        return (bool) config('tedc.demo_site', false);
    }
}
