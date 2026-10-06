<?php

namespace App\Security;

use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/** Settings → Security: the password policy and lockout, MFA enforcement and session lifetimes. Every change is audited by the controller. */
class SecurityPolicy
{
    public const KEY = 'security_policy';

    private const CACHE = 'site.security_policy';

    public static function defaults(): array
    {
        return [
            'password' => ['min_length' => 10, 'upper' => true, 'lower' => true, 'digit' => true, 'symbol' => false, 'history' => 5, 'expiry_days' => 0, 'breached_check' => false],
            'lockout' => ['max_attempts' => 5, 'minutes' => 15],
            'mfa' => ['enforce_roles' => [], 'methods' => ['totp', 'email', 'sms'], 'remember_days' => 30],
            'sessions' => ['idle_minutes' => 120, 'absolute_hours' => 12],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, function () {
            $stored = Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()));
            $out = self::defaults();
            // Section by section: a shorter list the administrator saved (fewer methods, fewer roles) must not get the defaults' extra entries back.
            foreach ($out as $section => $values) {
                $out[$section] = array_replace($values, (array) ($stored[$section] ?? []));
            }

            return $out;
        });
    }

    /** @param  array<string, array<string, mixed>>  $input */
    public function update(array $input, ?User $by = null): array
    {
        $cur = $this->all();
        $p = $input['password'] ?? [];
        $cur['password'] = [
            'min_length' => max(8, min(64, (int) ($p['min_length'] ?? $cur['password']['min_length']))),
            'upper' => (bool) ($p['upper'] ?? $cur['password']['upper']), 'lower' => (bool) ($p['lower'] ?? $cur['password']['lower']),
            'digit' => (bool) ($p['digit'] ?? $cur['password']['digit']), 'symbol' => (bool) ($p['symbol'] ?? $cur['password']['symbol']),
            'history' => max(0, min(24, (int) ($p['history'] ?? $cur['password']['history']))), 'expiry_days' => max(0, min(730, (int) ($p['expiry_days'] ?? $cur['password']['expiry_days']))),
            'breached_check' => (bool) ($p['breached_check'] ?? $cur['password']['breached_check']),
        ];
        if (isset($input['lockout'])) {
            $cur['lockout'] = ['max_attempts' => max(3, min(20, (int) ($input['lockout']['max_attempts'] ?? 5))), 'minutes' => max(1, min(1440, (int) ($input['lockout']['minutes'] ?? 15)))];
        }
        if (isset($input['mfa'])) {
            $cur['mfa'] = [
                'enforce_roles' => array_values(array_intersect((array) ($input['mfa']['enforce_roles'] ?? []), Role::pluck('slug')->all())),
                'methods' => array_values(array_intersect((array) ($input['mfa']['methods'] ?? ['totp']), ['totp', 'email', 'sms'])) ?: ['totp'],
                'remember_days' => max(0, min(90, (int) ($input['mfa']['remember_days'] ?? 30))),
            ];
        }
        if (isset($input['sessions'])) {
            $cur['sessions'] = ['idle_minutes' => max(5, min(1440, (int) ($input['sessions']['idle_minutes'] ?? 120))), 'absolute_hours' => max(1, min(168, (int) ($input['sessions']['absolute_hours'] ?? 12)))];
        }
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $cur, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->all();
    }

    /** Whether this person must use a second factor (their role says so, or they chose to enrol). */
    public function mfaRequired(User $user): bool
    {
        if ($user->mfa_enabled) {
            return true;
        }
        $roles = $user->roles->pluck('slug')->all();

        return array_intersect($roles, $this->all()['mfa']['enforce_roles']) !== [];
    }
}
