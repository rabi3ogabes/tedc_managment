<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Throwable;

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

    private const CACHE = 'demo.button_accounts';

    /**
     * Whether the demo buttons may be shown. They sign in with the shared demo password, so two things must hold: at least one of those
     * accounts really opens with it (a system that went live with real data has none, and a button that cannot work is not shown), and
     * none of those addresses belongs to a real person. A real administrator whose address happens to be a demo one («admin@…») must never
     * have the shared password tried on it by whoever presses the button: the failed tries lock the real account.
     */
    public static function buttonAccountsExist(): bool
    {
        $accounts = self::buttonAccounts();

        return $accounts['working'] !== [] && $accounts['real'] === [];
    }

    /**
     * Which of the buttons' addresses exist, split into the demo accounts (the password is still the shared one) and the real ones.
     * Kept for five minutes: one password check per account, not one per visit.
     *
     * @return array{working: list<string>, real: list<string>}
     */
    public static function buttonAccounts(): array
    {
        return Cache::remember(self::CACHE, 300, function () {
            $out = ['working' => [], 'real' => []];
            foreach (User::whereIn('email', self::BUTTON_EMAILS)->get(['email', 'password']) as $user) {
                $out[self::usesDemoPassword($user) ? 'working' : 'real'][] = $user->email;
            }

            return $out;
        });
    }

    /** Forgets the answer (after accounts are created, removed or their passwords change). */
    public static function forgetButtonAccounts(): void
    {
        Cache::forget(self::CACHE);
    }

    private static function usesDemoPassword(User $user): bool
    {
        $hash = (string) $user->getRawOriginal('password');
        if ($hash === '') {
            return false;      // signs in elsewhere (Supabase, the Ministry's account): a real account
        }
        try {
            return Hash::check(DemoCredentials::PASSWORD, $hash);
        } catch (Throwable) {
            return false;
        }
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
