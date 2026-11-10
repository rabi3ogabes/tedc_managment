<?php

namespace App\Integrations\Identity;

use App\Auth\AuthService;
use App\Integrations\IntegrationManager;
use App\Security\Sso\IdentityProvisioner;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Sign in with a directory (LDAP / Active Directory) username and password; the person's account is created or linked like an SSO sign-in. */
class DirectoryLogin
{
    public function __construct(private readonly IntegrationManager $hub, private readonly IdentityProvisioner $provisioner, private readonly AuthService $auth) {}

    public function enabled(): bool
    {
        return $this->hub->isReady('ldap') && ($this->hub->get('ldap')->driver !== 'fake' || ! app()->isProduction());
    }

    /** @return array<string, mixed> a session */
    public function login(string $username, string $password): array
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['username' => __('auth.sso_off')])->status(404);
        }
        $driver = $this->hub->get('ldap')->driver === 'fake' ? new FakeDirectory : app(LdapDirectory::class);
        try {
            $profile = $this->hub->call('ldap', 'authenticate', fn (array $s) => $driver->authenticate($username, $password, $s), ['username' => '•••'], 0);
        } catch (Throwable) {
            throw ValidationException::withMessages(['username' => __('auth.ldap_failed')])->status(401);
        }
        if (! $profile) {
            throw ValidationException::withMessages(['username' => __('auth.ldap_failed')])->status(401);
        }
        $user = $this->provisioner->resolve('ldap', ['subject' => $profile['subject'], 'email' => $profile['email'], 'upn' => $profile['email'], 'name' => $profile['name'], 'employee_no' => $profile['employee_no'], 'groups' => $profile['groups']], $this->hub->settings('ldap') + ['jit' => true]);

        return $this->auth->issueForUser($user, 'ldap');
    }
}
