<?php

namespace App\Support;

use App\Models\User;

/**
 * Demo data (accounts with a shared password, sample programs, scenarios) never appears on a live system. Outside
 * production it is always allowed; in production it never is. A demonstration deployment runs with APP_ENV=staging
 * (or demo), not production. The old TEDC_ALLOW_DEMO_IN_PRODUCTION switch is ignored on purpose: since 11 Oct 2026 the
 * live site is preparing for real data. The one exception is a deployment declared a demonstration site with
 * TEDC_DEMO_SITE=true in its hosting environment (see allowed()).
 */
class DemoGuard
{
    /** The accounts the sign-in screens' demo buttons open (web and app): the demo organisation's and the app test accounts. */
    public const BUTTON_EMAILS = [
        'admin@tedc.qa', 'center@tedc.qa', 'coordinator@tedc.qa', 'trainer@tedc.qa', 'school@tedc.qa', 'teacher@tedc.qa', 'supervisor@tedc.qa', 'executive@tedc.qa',
        'trainee1@tedc.qa', 'trainee2@tedc.qa', 'trainee3@tedc.qa', 'trainee4@tedc.qa', 'trainer1@tedc.qa', 'trainer2@tedc.qa',
    ];

    /** Whether any of those accounts exists: a system that went live with real data has none, and buttons that cannot work are not shown. */
    public static function buttonAccountsExist(): bool
    {
        return User::whereIn('email', self::BUTTON_EMAILS)->exists();
    }

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
