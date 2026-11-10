<?php

namespace App\Security\Sso;

use App\Models\Employee;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Validation\ValidationException;

/**
 * Finds or creates the platform account for a person the identity provider (Entra ID, SAML, LDAP) vouches for, links the
 * identity, and keeps their roles in line with the directory groups (configurable map, respecting scopes). Just-in-time
 * creation is optional. An existing account is claimed by e-mail (or UPN, or employee number) only when the provider speaks
 * for that account's domain (the allowed domains) or marks the e-mail verified: a look-alike claim never takes over an account.
 */
class IdentityProvisioner
{
    /**
     * @param  array{subject: string, email?: ?string, email_verified?: ?bool, upn?: ?string, name?: ?string, name_ar?: ?string, employee_no?: ?string, groups?: list<string>}  $p
     * @param  array<string, mixed>  $settings  integration settings (jit, allowed_domains, group_map)
     */
    public function resolve(string $provider, array $p, array $settings): User
    {
        ['user' => $user, 'email' => $email] = $this->match($provider, $p, $settings);

        if (! $user) {
            $user = User::create(['name' => ($p['name'] ?? null) ?: (($p['name_ar'] ?? null) ?: $email), 'name_ar' => ($p['name_ar'] ?? null) ?: null, 'email' => $email, 'locale' => 'ar', 'status' => 'active']);
            if ($role = Role::where('slug', Role::EMPLOYEE)->first()) {
                $this->grant($user, $role, 'ministry', null);
            }
            if (! empty($p['employee_no']) && ($e = Employee::where('employee_no', $p['employee_no'])->whereNull('user_id')->first())) {
                $e->forceFill(['user_id' => $user->id])->save();
            }
        }
        if ($user->status !== 'active') {
            throw ValidationException::withMessages(['email' => __('auth.not_provisioned')])->status(403);
        }

        UserIdentity::updateOrCreate(['provider' => $provider, 'subject' => $p['subject']], ['user_id' => $user->id, 'upn' => $p['upn'] ?? $email ?: null, 'last_login_at' => now()]);
        $this->syncGroups($user, $p['groups'] ?? [], $settings['group_map'] ?? null);

        return $user->load('roles');
    }

    /**
     * What a sign-in would do, changing nothing (Settings → Single sign-on → Test sign-in): the account it reaches and the
     * roles the groups give.
     *
     * @return array{outcome: string, message: ?string, user: ?array<string, mixed>, roles: list<array<string, mixed>>, ignored: list<array<string, mixed>>}
     */
    public function preview(string $provider, array $p, array $settings): array
    {
        [$grants, $ignored] = $this->mapGroups($p['groups'] ?? [], $settings['group_map'] ?? null);
        try {
            ['user' => $user, 'outcome' => $outcome] = $this->match($provider, $p, $settings);
            if ($user && $user->status !== 'active') {
                throw ValidationException::withMessages(['email' => __('auth.not_provisioned')]);
            }
        } catch (ValidationException $e) {
            return ['outcome' => 'refused', 'message' => $e->getMessage(), 'user' => null, 'roles' => [], 'ignored' => $ignored];
        }
        $roles = array_map(fn ($g) => ['role' => $g['role']->slug, 'scope_type' => $g['scope_type'], 'scope_id' => $g['scope_id'], 'group' => $g['group']], $grants);
        if ($outcome === 'create') {
            array_unshift($roles, ['role' => Role::EMPLOYEE, 'scope_type' => 'ministry', 'scope_id' => null, 'group' => null]);
        }

        return ['outcome' => $outcome, 'message' => null, 'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null, 'roles' => $roles, 'ignored' => $ignored];
    }

    /** @param  list<string>  $groups  @param  array<int, array<string, mixed>>|string|null  $map */
    public function syncGroups(User $user, array $groups, array|string|null $map): void
    {
        foreach ($this->mapGroups($groups, $map)[0] as $g) {
            $this->grant($user, $g['role'], $g['scope_type'], $g['scope_id']);
        }
    }

    /**
     * The account the person reaches: the one linked to this identity, an existing one the provider may vouch for, or a new one.
     *
     * @return array{user: ?User, outcome: 'linked'|'link'|'create', email: string}
     *
     * @throws ValidationException when the person may not sign in
     */
    private function match(string $provider, array $p, array $settings): array
    {
        $email = strtolower(trim((string) ($p['email'] ?? $p['upn'] ?? '')));
        $this->assertDomain($email, (string) ($settings['allowed_domains'] ?? ''));

        if ($user = UserIdentity::where(['provider' => $provider, 'subject' => $p['subject']])->first()?->user) {
            return ['user' => $user, 'outcome' => 'linked', 'email' => $email];
        }
        $existing = ($email !== '' ? User::whereRaw('lower(email) = ?', [$email])->first() : null)
            ?? (! empty($p['upn']) ? User::whereRaw('lower(email) = ?', [strtolower($p['upn'])])->first() : null)
            ?? (! empty($p['employee_no']) ? Employee::where('employee_no', $p['employee_no'])->first()?->user : null);
        if ($existing) {
            if (! $this->vouches($p, $settings, $existing)) {
                throw ValidationException::withMessages(['email' => __('auth.sso_link_untrusted')])->status(403);
            }

            return ['user' => $existing, 'outcome' => 'link', 'email' => $email];
        }
        if (! ($settings['jit'] ?? true) || $email === '') {
            throw ValidationException::withMessages(['email' => __('auth.not_provisioned')])->status(403);
        }

        return ['user' => null, 'outcome' => 'create', 'email' => $email];
    }

    /** The provider may claim an existing account when it speaks for the account's domain, or marks the e-mail verified. */
    private function vouches(array $p, array $settings, User $existing): bool
    {
        if (($p['email_verified'] ?? null) === true && strtolower((string) ($p['email'] ?? '')) === strtolower($existing->email)) {
            return true;
        }

        return in_array(strtolower(substr(strrchr($existing->email, '@') ?: '', 1)), $this->domains((string) ($settings['allowed_domains'] ?? '')), true);
    }

    /**
     * The roles the person's groups give, and the mappings that are left out (a directory group never makes someone a super
     * administrator; an unknown role does nothing).
     *
     * @param  list<string>  $groups  @param  array<int, array<string, mixed>>|string|null  $map
     * @return array{0: list<array{role: Role, scope_type: string, scope_id: ?string, group: string}>, 1: list<array{group: string, role: string, reason: string}>}
     */
    private function mapGroups(array $groups, array|string|null $map): array
    {
        $map = is_string($map) ? (json_decode($map, true) ?: []) : ($map ?: []);
        $groups = array_map('strtolower', $groups);
        $grants = $ignored = [];
        foreach ($map as $m) {
            if (! in_array(strtolower((string) ($m['group'] ?? '')), $groups, true) || empty($m['role'])) {
                continue;
            }
            $role = Role::where('slug', $m['role'])->first();
            if (! $role || $role->slug === Role::SUPER_ADMIN) {
                $ignored[] = ['group' => (string) $m['group'], 'role' => (string) $m['role'], 'reason' => $role ? 'super_admin' : 'unknown_role'];

                continue;
            }
            $scopeType = in_array($m['scope_type'] ?? 'ministry', RoleUser::SCOPES, true) ? (string) ($m['scope_type'] ?? 'ministry') : 'ministry';
            $grants[] = ['role' => $role, 'scope_type' => $scopeType, 'scope_id' => $m['scope_id'] ?? null, 'group' => (string) $m['group']];
        }

        return [$grants, $ignored];
    }

    private function grant(User $user, Role $role, string $scopeType, ?string $scopeId): void
    {
        if (! in_array($scopeType, RoleUser::SCOPES, true)) {
            $scopeType = 'ministry';
        }
        $exists = RoleUser::where(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $scopeType])->where(fn ($q) => $scopeId ? $q->where('scope_id', $scopeId) : $q->whereNull('scope_id'))->exists();
        if (! $exists) {
            RoleUser::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'granted_at' => now()]);
        }
    }

    /** @return list<string> */
    private function domains(string $allowed): array
    {
        return array_values(array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', $allowed))));
    }

    private function assertDomain(string $email, string $allowed): void
    {
        $list = $this->domains($allowed);
        if ($list !== [] && ! in_array(strtolower(substr(strrchr($email, '@') ?: '', 1)), $list, true)) {
            throw ValidationException::withMessages(['email' => __('auth.sso_domain')])->status(403);
        }
    }
}
