<?php

namespace App\Integrations\Identity;

use RuntimeException;

/**
 * Bind-and-search authentication against LDAP / Active Directory: a service account finds the person's entry, then the person's own
 * password is checked by binding as them. TLS is required (ldaps or STARTTLS). Needs PHP's ldap extension on the host.
 */
class LdapDirectory implements DirectoryClient
{
    public function authenticate(string $username, string $password, array $s): ?array
    {
        if ($username === '' || $password === '') {
            return null;   // an empty password would be an anonymous bind, which many servers accept
        }
        $conn = $this->connect($s);
        $this->bind($conn, (string) ($s['bind_dn'] ?? ''), (string) ($s['bind_password'] ?? ''));
        $filter = str_replace('{username}', ldap_escape($username, '', LDAP_ESCAPE_FILTER), (string) ($s['user_filter'] ?? '(sAMAccountName={username})'));
        $attrs = array_values(array_filter([$s['email_attr'] ?? 'mail', $s['name_attr'] ?? 'displayName', $s['employee_no_attr'] ?? 'employeeID', 'memberOf', 'userPrincipalName', 'objectGUID']));
        $search = @ldap_search($conn, (string) ($s['base_dn'] ?? ''), $filter, $attrs, 0, 2);
        $entries = $search ? ldap_get_entries($conn, $search) : ['count' => 0];
        if (($entries['count'] ?? 0) !== 1) {
            return null;   // not found, or ambiguous
        }
        $e = $entries[0];
        if (! @ldap_bind($conn, $e['dn'], $password)) {
            return null;
        }
        $attr = fn (string $k) => $e[strtolower($k)][0] ?? null;

        return [
            'subject' => (string) ($attr('objectGUID') ? bin2hex($attr('objectGUID')) : $e['dn']), 'email' => $attr($s['email_attr'] ?? 'mail') ?? $attr('userPrincipalName'), 'name' => $attr($s['name_attr'] ?? 'displayName'),
            'employee_no' => $attr($s['employee_no_attr'] ?? 'employeeID'), 'groups' => array_values(array_filter(array_map(fn ($g) => is_string($g) ? $g : null, array_slice($e['memberof'] ?? [], 1)))),
        ];
    }

    public function ping(array $s): bool
    {
        $conn = $this->connect($s);
        $this->bind($conn, (string) ($s['bind_dn'] ?? ''), (string) ($s['bind_password'] ?? ''));

        return true;
    }

    private function connect(array $s)
    {
        if (! function_exists('ldap_connect')) {
            throw new RuntimeException('The PHP ldap extension is not installed on this server.');
        }
        if (empty($s['use_tls'] ?? true)) {
            throw new RuntimeException('LDAP without TLS is not allowed.');
        }
        $port = (int) ($s['port'] ?? 636);
        $conn = ldap_connect(($port === 636 ? 'ldaps://' : 'ldap://').($s['host'] ?? '').':'.$port);
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 8);
        if ($port !== 636 && ! @ldap_start_tls($conn)) {
            throw new RuntimeException('The directory refused STARTTLS.');
        }

        return $conn;
    }

    private function bind($conn, string $dn, string $password): void
    {
        if ($dn === '' || ! @ldap_bind($conn, $dn, $password)) {
            throw new RuntimeException('The service account could not bind.');
        }
    }
}
