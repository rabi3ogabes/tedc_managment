<?php

namespace App\Services;

use App\Models\Role;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;

/**
 * What each kind of person sees on the mobile app's home screen: which cards, in which order, and which action tiles
 * (the things waiting for them). Every role has a default view, and the administrator changes any of them from the
 * dashboard (Settings → Brand Identity Studio → App home screen). Only the choices are stored.
 */
class AppHomeLayout
{
    public const KEY = 'app_home';

    /** The cards of a home screen. `actions` is the row of tiles below; `today` the sessions of the day. */
    public const CARDS = ['hours', 'todo', 'stats', 'scan', 'needs', 'programs', 'dashboard', 'sessions', 'recommended', 'actions', 'today'];

    /** The action tiles (what is waiting for the person). Each is shown only to people who hold its permission. */
    public const ACTIONS = ['approvals_manager', 'approvals_center', 'profile_requests', 'assignments', 'errors'];

    /** The kinds of people with their own view. */
    public const ROLES = [Role::EMPLOYEE, Role::TRAINER, Role::SCHOOL_ADMIN, Role::SUPERVISOR, Role::COORDINATOR, Role::CENTER_ADMIN, Role::SUPER_ADMIN, Role::EXECUTIVE];

    /** The cards each kind of person starts with, in order. Every other card starts switched off. */
    private const DEFAULT_CARDS = [
        Role::EMPLOYEE => ['programs', 'hours', 'todo', 'stats', 'scan', 'needs', 'dashboard', 'sessions', 'recommended'],
        Role::TRAINER => ['today', 'actions', 'dashboard'],
        Role::SCHOOL_ADMIN => ['actions', 'dashboard', 'needs'],
        Role::SUPERVISOR => ['actions', 'dashboard'],
        Role::COORDINATOR => ['actions', 'today', 'dashboard'],
        Role::CENTER_ADMIN => ['actions', 'today', 'dashboard'],
        Role::SUPER_ADMIN => ['actions', 'today', 'dashboard'],
        Role::EXECUTIVE => ['dashboard', 'actions', 'today'],
    ];

    public static function universe(): array
    {
        return [...self::CARDS, ...self::ACTIONS];
    }

    /** @return list<array{key: string, enabled: bool}> the starting list of a role: its cards in order, then everything else (actions on, other cards off) */
    public function defaults(string $role): array
    {
        $cards = self::DEFAULT_CARDS[$role] ?? self::DEFAULT_CARDS[Role::CENTER_ADMIN];
        $out = array_map(fn ($k) => ['key' => $k, 'enabled' => true], $cards);
        foreach (self::CARDS as $k) {
            if (! in_array($k, $cards, true)) {
                $out[] = ['key' => $k, 'enabled' => false];
            }
        }
        foreach (self::ACTIONS as $k) {
            $out[] = ['key' => $k, 'enabled' => true];
        }

        return $out;
    }

    private function stored(): array
    {
        return (array) Cache::remember('site.app_home', 60, fn () => SiteSetting::find(self::KEY)?->value ?? []);
    }

    /** Whether the administrator has set this role's view (or, for trainees, the older whole-app order). */
    public function customised(string $role = Role::EMPLOYEE): bool
    {
        $s = $this->stored();

        return isset($s['roles'][$role]) || ($role === Role::EMPLOYEE && ! empty($s['sections']));
    }

    /** @return list<array{key: string, enabled: bool}> */
    public function get(string $role): array
    {
        $s = $this->stored();
        $list = $s['roles'][$role] ?? ($role === Role::EMPLOYEE && ! empty($s['sections']) ? $s['sections'] : null);

        return $list === null ? $this->defaults($role) : $this->normalise((array) $list, $role);
    }

    /** The card keys switched on, in order. @return list<string> */
    public function cards(string $role): array
    {
        return array_values(array_map(fn ($s) => $s['key'], array_filter($this->get($role), fn ($s) => $s['enabled'] && in_array($s['key'], self::CARDS, true))));
    }

    /** The action tiles switched on, in order. @return list<string> */
    public function actions(string $role): array
    {
        return array_values(array_map(fn ($s) => $s['key'], array_filter($this->get($role), fn ($s) => $s['enabled'] && in_array($s['key'], self::ACTIONS, true))));
    }

    /** @param  list<array{key: string, enabled?: bool}>  $sections */
    public function save(string $role, array $sections, string $userId): array
    {
        $clean = $this->normalise($sections, $role);
        $value = $this->stored();
        $value['roles'][$role] = $clean;
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $value, 'updated_by' => $userId]);
        Cache::forget('site.app_home');

        return $clean;
    }

    /** Back to the defaults: one role, or every role when none is given. */
    public function reset(?string $role = null): void
    {
        if ($role === null) {
            SiteSetting::where('key', self::KEY)->delete();
        } else {
            $value = $this->stored();
            unset($value['roles'][$role]);
            if ($role === Role::EMPLOYEE) {
                unset($value['sections']);
            }
            SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $value]);
        }
        Cache::forget('site.app_home');
    }

    /** Unknown keys are dropped, repeats ignored, and a key the list leaves out is added at the end in the state it starts in for that role. */
    private function normalise(array $sections, string $role): array
    {
        $start = array_column($this->defaults($role), 'enabled', 'key');
        $out = [];
        foreach ($sections as $s) {
            $key = is_array($s) ? ($s['key'] ?? null) : null;
            if (in_array($key, self::universe(), true) && ! isset($out[$key])) {
                $out[$key] = ['key' => $key, 'enabled' => (bool) ($s['enabled'] ?? true)];
            }
        }
        foreach (self::universe() as $key) {
            $out[$key] ??= ['key' => $key, 'enabled' => $start[$key] ?? false];
        }

        return array_values($out);
    }
}
