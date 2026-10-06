<?php

namespace App\Security\Sso;

use App\Models\Employee;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Validation\ValidationException;

/**
 * Finds or creates the platform account for a person the identity provider (Entra ID, LDAP) vouches for, links the identity,
 * and keeps their roles in line with the directory groups (configurable map, respecting scopes). Just-in-time creation is optional.
 */
class IdentityProvisioner
{
    /**
     * @param  array{subject: string, email?: ?string, upn?: ?string, name?: ?string, employee_no?: ?string, groups?: list<string>}  $p
     * @param  array<string, mixed>  $settings  integration settings (jit, allowed_domains, group_map)
     */
    public function resolve(string $provider, array $p, array $settings): User
    {
        $email = strtolower(trim((string) ($p['email'] ?? $p['upn'] ?? '')));
        $this->assertDomain($email, (string) ($settings['allowed_domains'] ?? ''));

        $user = UserIdentity::where(['provider' => $provider, 'subject' => $p['subject']])->first()?->user
            ?? ($email !== '' ? User::whereRaw('lower(email) = ?', [$email])->first() : null)
            ?? (! empty($p['upn']) ? User::whereRaw('lower(email) = ?', [strtolower($p['upn'])])->first() : null)
            ?? (! empty($p['employee_no']) ? Employee::where('employee_no', $p['employee_no'])->first()?->user : null);

        if (! $user) {
            if (! ($settings['jit'] ?? true) || $email === '') {
                throw ValidationException::withMessages(['email' => __('auth.not_provisioned')])->status(403);
            }
            $user = User::create(['name' => $p['name'] ?: $email, 'email' => $email, 'locale' => 'ar', 'status' => 'active']);
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

    /** @param  list<string>  $groups  @param  array<int, array<string, mixed>>|string|null  $map */
    public function syncGroups(User $user, array $groups, array|string|null $map): void
    {
        $map = is_string($map) ? (json_decode($map, true) ?: []) : ($map ?: []);
        $groups = array_map('strtolower', $groups);
        foreach ($map as $m) {
            if (! in_array(strtolower((string) ($m['group'] ?? '')), $groups, true) || empty($m['role'])) {
                continue;
            }
            $role = Role::where('slug', $m['role'])->first();
            if (! $role || $role->slug === Role::SUPER_ADMIN) {
                continue;   // a directory group never makes someone a super administrator
            }
            $this->grant($user, $role, (string) ($m['scope_type'] ?? 'ministry'), $m['scope_id'] ?? null);
        }
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

    private function assertDomain(string $email, string $allowed): void
    {
        $list = array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', $allowed)));
        if ($list !== [] && ! in_array(strtolower(substr(strrchr($email, '@') ?: '', 1)), $list, true)) {
            throw ValidationException::withMessages(['email' => __('auth.sso_domain')])->status(403);
        }
    }
}
