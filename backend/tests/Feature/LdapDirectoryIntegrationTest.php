<?php

namespace Tests\Feature;

use App\Integrations\Identity\LdapDirectory;
use App\Integrations\IntegrationManager;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Tests\TestCase;

/**
 * NFR-08: directory sign-in proven against a real LDAP server (OpenLDAP with TLS, in the backend-ldap CI job). The job seeds
 * tests/Fixtures/ldap/seed.ldif and sets LDAP_TEST_HOST, LDAP_TEST_ADMIN_PASSWORD, LDAP_TEST_USER_PASSWORD and LDAP_TEST_CA_FILE
 * (the job's own certificate authority, which the runner does not trust: TEDC is given it through the ca_certificate setting).
 */
#[Group('ldap')]
class LdapDirectoryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('ldap_connect') || ! getenv('LDAP_TEST_HOST')) {
            $this->markTestSkipped('Runs in the backend-ldap CI job against OpenLDAP (needs the ldap extension and LDAP_TEST_HOST).');
        }
    }

    /** @return array<string, mixed> */
    private function settings(array $o = []): array
    {
        return $o + [
            'host' => getenv('LDAP_TEST_HOST'), 'port' => 636, 'use_tls' => true, 'base_dn' => 'dc=tedc,dc=test', 'bind_dn' => 'cn=admin,dc=tedc,dc=test',
            'bind_password' => getenv('LDAP_TEST_ADMIN_PASSWORD'), 'user_filter' => '(uid={username})', 'active_filter' => '(!(employeeType=disabled))',
            'email_attr' => 'mail', 'name_attr' => 'cn', 'employee_no_attr' => 'employeeNumber', 'nested_groups' => 'recursive', 'timeout' => 5,
            'ca_certificate' => (string) file_get_contents((string) getenv('LDAP_TEST_CA_FILE')),
        ];
    }

    private function password(): string
    {
        return (string) getenv('LDAP_TEST_USER_PASSWORD');
    }

    public function test_bind_and_search_finds_the_person_and_their_groups(): void
    {
        $p = (new LdapDirectory)->authenticate('sara', $this->password(), $this->settings());

        $this->assertSame('sara@moe.gov.qa', $p['email']);
        $this->assertSame('Sara Ali', $p['name']);
        $this->assertSame('E100', $p['employee_no']);
        $this->assertContains('cn=trainers,ou=groups,dc=tedc,dc=test', array_map('strtolower', $p['groups']));
        $this->assertContains('trainers', $p['groups']);
        $this->assertNotSame('', $p['subject']);
    }

    public function test_a_wrong_password_fails(): void
    {
        $this->assertNull((new LdapDirectory)->authenticate('sara', $this->password().'x', $this->settings()));
    }

    public function test_a_disabled_account_fails_even_with_its_password(): void
    {
        $this->assertNull((new LdapDirectory)->authenticate('old', $this->password(), $this->settings()));
        $this->assertNotNull((new LdapDirectory)->authenticate('old', $this->password(), $this->settings(['active_filter' => ''])), 'the account itself exists');
    }

    public function test_nested_groups_map_to_roles(): void
    {
        // nadia is in "leads", which is inside "trainers".
        $direct = (new LdapDirectory)->authenticate('nadia', $this->password(), $this->settings(['nested_groups' => 'direct']));
        $this->assertNotContains('trainers', $direct['groups']);
        $nested = (new LdapDirectory)->authenticate('nadia', $this->password(), $this->settings());
        $this->assertContains('leads', $nested['groups']);
        $this->assertContains('trainers', $nested['groups']);

        app(IntegrationManager::class)->update('ldap', ['driver' => 'ldap', 'enabled' => true, 'settings' => $this->settings(['group_map' => json_encode([['group' => 'trainers', 'role' => 'trainer']])])]);
        $this->withHeader('Authorization', '')->postJson('/api/v1/auth/ldap/login', ['username' => 'nadia', 'password' => $this->password()])->assertOk();
        $this->assertContains('trainer', User::where('email', 'nadia@moe.gov.qa')->firstOrFail()->roles()->pluck('slug')->all());
    }

    public function test_plain_ldap_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        (new LdapDirectory)->authenticate('sara', $this->password(), $this->settings(['use_tls' => false, 'port' => 389]));
    }

    public function test_starttls_on_the_plain_port_is_used_and_accepted(): void
    {
        $this->assertNotNull((new LdapDirectory)->authenticate('sara', $this->password(), $this->settings(['port' => 389])));
    }

    public function test_a_certificate_for_another_name_is_refused(): void
    {
        // The server's certificate names "localhost" only.
        $this->expectException(RuntimeException::class);
        (new LdapDirectory)->authenticate('sara', $this->password(), $this->settings(['host' => '127.0.0.1']));
    }

    /** TLS settings are process-wide in libldap, so this runs alone: without the CA, the directory is not trusted. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_directory_whose_certificate_is_not_trusted_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        (new LdapDirectory)->authenticate('sara', $this->password(), $this->settings(['ca_certificate' => '']));
    }

    public function test_filter_injection_finds_nobody(): void
    {
        foreach (['*', 'sara)(uid=*', '*)(|(uid=*', "sara\x00", 'sa*'] as $u) {
            $this->assertNull((new LdapDirectory)->authenticate($u, $this->password(), $this->settings()), $u);
        }
    }

    public function test_an_unreachable_directory_times_out(): void
    {
        $started = microtime(true);
        try {
            (new LdapDirectory)->authenticate('sara', $this->password(), $this->settings(['host' => '10.255.255.1', 'timeout' => 2]));
            $this->fail('An unreachable directory must not sign anyone in.');
        } catch (RuntimeException) {
            $this->assertLessThan(10, microtime(true) - $started);
        }
    }
}
