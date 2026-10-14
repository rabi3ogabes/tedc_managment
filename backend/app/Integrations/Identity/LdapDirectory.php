<?php

namespace App\Integrations\Identity;

use RuntimeException;

/**
 * Bind-and-search authentication against LDAP / Active Directory: a service account finds the person's entry (active accounts
 * only), the person's own password is checked by binding as them, and the service account then reads their groups, nested groups
 * included. TLS is required (LDAPS, or STARTTLS on another port) and the server's certificate is always verified. Needs PHP's ldap
 * extension on the host; proven against OpenLDAP in the backend-ldap CI job.
 */
class LdapDirectory implements DirectoryClient
{
    /** Active Directory: leave out disabled accounts (userAccountControl bit 2). */
    public const AD_ACTIVE = '(!(userAccountControl:1.2.840.113556.1.4.803:=2))';

    /** Active Directory's transitive membership rule (LDAP_MATCHING_RULE_IN_CHAIN). */
    private const IN_CHAIN = '1.2.840.113556.1.4.1941';

    private const GROUP_CLASSES = '(|(objectClass=group)(objectClass=groupOfNames)(objectClass=groupOfUniqueNames))';

    private const MAX_DEPTH = 10;

    private const MAX_GROUPS = 1000;

    public function authenticate(string $username, string $password, array $s): ?array
    {
        if ($username === '' || $password === '') {
            return null;   // an empty password would be an anonymous bind, which many servers accept
        }
        $conn = $this->connect($s);
        $this->bind($conn, (string) ($s['bind_dn'] ?? ''), (string) ($s['bind_password'] ?? ''));
        $attrs = array_values(array_filter([$s['email_attr'] ?? 'mail', $s['name_attr'] ?? 'displayName', $s['employee_no_attr'] ?? 'employeeID', 'memberOf', 'userPrincipalName', 'objectGUID', 'entryUUID']));
        $search = @ldap_search($conn, (string) ($s['base_dn'] ?? ''), self::userFilter($username, $s), $attrs, 0, 2);
        $entries = $search ? ldap_get_entries($conn, $search) : ['count' => 0];
        if (($entries['count'] ?? 0) !== 1) {
            return null;   // not found, disabled, or ambiguous
        }
        $e = $entries[0];
        if (! @ldap_bind($conn, $e['dn'], $password)) {
            return null;
        }
        // Groups are read as the service account: people often may not read group entries themselves.
        $this->bind($conn, (string) ($s['bind_dn'] ?? ''), (string) ($s['bind_password'] ?? ''));
        $attr = fn (string $k) => $e[strtolower($k)][0] ?? null;
        $memberOf = array_values(array_filter(array_slice($e['memberof'] ?? [], 1), 'is_string'));

        return [
            'subject' => (string) ($attr('objectGUID') ? bin2hex($attr('objectGUID')) : ($attr('entryUUID') ?: $e['dn'])), 'email' => $attr($s['email_attr'] ?? 'mail') ?? $attr('userPrincipalName'),
            'name' => $attr($s['name_attr'] ?? 'displayName'), 'employee_no' => $attr($s['employee_no_attr'] ?? 'employeeID'),
            'groups' => self::groupNames($this->groups($conn, $s, $e['dn'], $memberOf)),
        ];
    }

    public function ping(array $s): bool
    {
        $conn = $this->connect($s);
        $this->bind($conn, (string) ($s['bind_dn'] ?? ''), (string) ($s['bind_password'] ?? ''));

        return true;
    }

    /** RFC 4515 escaping for a value inside a search filter (no extension needed). */
    public static function escapeFilter(string $value): string
    {
        return strtr($value, ['\\' => '\\5c', '*' => '\\2a', '(' => '\\28', ')' => '\\29', "\x00" => '\\00']);
    }

    /** The person's search filter, limited to active accounts unless the rule is cleared. */
    public static function userFilter(string $username, array $s): string
    {
        $filter = str_replace('{username}', self::escapeFilter($username), (string) (($s['user_filter'] ?? '') ?: '(sAMAccountName={username})'));
        $active = trim((string) ($s['active_filter'] ?? self::AD_ACTIVE));

        return $active === '' ? $filter : '(&'.$filter.$active.')';
    }

    /** The common name of a group from its distinguished name ("CN=Trainers,OU=…" → "Trainers"); null when it does not start with CN. */
    public static function cnOf(string $dn): ?string
    {
        if (! preg_match('/^\s*cn\s*=\s*((?:\\\\.|[^,+\\\\])*)/i', $dn, $m)) {
            return null;
        }
        $value = preg_replace_callback('/\\\\([0-9a-fA-F]{2}|.)/s', fn ($x) => strlen($x[1]) === 2 ? chr((int) hexdec($x[1])) : $x[1], $m[1]);

        return trim($value);
    }

    /**
     * What a group map may name: every group's full name, then each common name once (first spelling kept).
     *
     * @param  array<string, array{0: string, 1: ?string}>  $found  lower-cased DN => [DN, common name]
     * @return list<string>
     */
    public static function groupNames(array $found): array
    {
        $names = array_column($found, 0);
        $seen = [];
        foreach ($found as [, $cn]) {
            if ($cn !== null && $cn !== '' && ! isset($seen[mb_strtolower($cn)])) {
                $seen[mb_strtolower($cn)] = true;
                $names[] = $cn;
            }
        }

        return $names;
    }

    /**
     * The person's groups: "direct" (their own), "recursive" (groups inside groups, any directory) or "in_chain" (Active
     * Directory's own transitive search, falling back to recursive).
     *
     * @param  list<string>  $memberOf
     * @return array<string, array{0: string, 1: ?string}>
     */
    private function groups($conn, array $s, string $dn, array $memberOf): array
    {
        $base = (string) (($s['groups_base_dn'] ?? '') ?: ($s['base_dn'] ?? ''));
        $mode = (string) ($s['nested_groups'] ?? 'recursive');
        $found = [];
        foreach ($memberOf as $g) {
            $found[strtolower($g)] = [$g, self::cnOf($g)];
        }
        if ($mode === 'in_chain') {
            $chain = $this->search($conn, $base, '(&'.self::GROUP_CLASSES.'(member:'.self::IN_CHAIN.':='.self::escapeFilter($dn).'))');
            if ($chain !== null && ($chain !== [] || $memberOf === [])) {
                return $found + $chain;
            }
        }
        $frontier = $mode === 'direct' ? [$dn] : array_merge([$dn], $memberOf);
        for ($depth = 0; $frontier !== [] && $depth < self::MAX_DEPTH && count($found) < self::MAX_GROUPS; $depth++) {
            $next = [];
            foreach ($this->groupsContaining($conn, $base, $frontier) as $k => $g) {
                if (! isset($found[$k])) {
                    $found[$k] = $g;
                    $next[] = $g[0];
                } elseif ($found[$k][1] === null) {
                    $found[$k][1] = $g[1];
                }
            }
            $frontier = $mode === 'direct' ? [] : $next;
        }

        return array_slice($found, 0, self::MAX_GROUPS, true);
    }

    /** @param  list<string>  $members  @return array<string, array{0: string, 1: ?string}> */
    private function groupsContaining($conn, string $base, array $members): array
    {
        $out = [];
        foreach (array_chunk($members, 20) as $chunk) {
            $or = implode('', array_map(fn ($m) => '(member='.self::escapeFilter($m).')(uniqueMember='.self::escapeFilter($m).')', $chunk));
            $out += $this->search($conn, $base, '(&'.self::GROUP_CLASSES.'(|'.$or.'))') ?? [];
        }

        return $out;
    }

    /** @return array<string, array{0: string, 1: ?string}>|null null when the directory refused the search */
    private function search($conn, string $base, string $filter): ?array
    {
        $r = @ldap_search($conn, $base, $filter, ['cn'], 0, self::MAX_GROUPS);
        if ($r === false) {
            return null;
        }
        $entries = ldap_get_entries($conn, $r);
        $out = [];
        for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
            $out[strtolower($entries[$i]['dn'])] = [$entries[$i]['dn'], $entries[$i]['cn'][0] ?? self::cnOf($entries[$i]['dn'])];
        }

        return $out;
    }

    private function connect(array $s)
    {
        if (! function_exists('ldap_connect')) {
            throw new RuntimeException('The PHP ldap extension is not installed on this server.');
        }
        if (empty($s['use_tls'] ?? true)) {
            throw new RuntimeException('LDAP without TLS is not allowed.');
        }
        // libldap keeps TLS settings per process; PHP starts a fresh TLS context for the next LDAPS connection after a change.
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
        if (defined('LDAP_OPT_X_TLS_PROTOCOL_MIN') && defined('LDAP_OPT_X_TLS_PROTOCOL_TLS1_2')) {
            ldap_set_option(null, LDAP_OPT_X_TLS_PROTOCOL_MIN, LDAP_OPT_X_TLS_PROTOCOL_TLS1_2);
        }
        if (trim((string) ($s['ca_certificate'] ?? '')) !== '') {
            ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $this->caFile((string) $s['ca_certificate']));
        }
        $port = (int) ($s['port'] ?? 636);
        $ldaps = in_array($port, [636, 3269], true);
        $conn = ldap_connect(($ldaps ? 'ldaps://' : 'ldap://').($s['host'] ?? '').':'.$port);
        if (! $conn) {
            throw new RuntimeException('The directory address is not valid.');
        }
        $timeout = max(1, min(60, (int) ($s['timeout'] ?? 8)));
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($conn, LDAP_OPT_TIMELIMIT, $timeout);
        if (defined('LDAP_OPT_TIMEOUT')) {
            ldap_set_option($conn, LDAP_OPT_TIMEOUT, $timeout);
        }
        if (! $ldaps && ! @ldap_start_tls($conn)) {
            throw new RuntimeException('The directory refused STARTTLS.');
        }

        return $conn;
    }

    /** The directory's certificate authority (PEM, from the settings) as a file libldap can read. */
    private function caFile(string $pem): string
    {
        if (! str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
            throw new RuntimeException('The directory CA certificate is not a PEM certificate.');
        }
        $path = storage_path('framework/ldap-ca-'.hash('sha256', $pem).'.pem');
        if (! is_file($path)) {
            $tmp = $path.'.'.bin2hex(random_bytes(4));
            file_put_contents($tmp, trim($pem)."\n");
            rename($tmp, $path);
        }

        return $path;
    }

    private function bind($conn, string $dn, string $password): void
    {
        if ($dn === '' || ! @ldap_bind($conn, $dn, $password)) {
            throw new RuntimeException('The service account could not bind.');
        }
    }
}
