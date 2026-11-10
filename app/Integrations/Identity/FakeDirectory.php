<?php

namespace App\Integrations\Identity;

/** A directory kept in the integration's own settings (`users`: [{username, password, email, name, employee_no, groups}]) — for tests, demos and training. */
class FakeDirectory implements DirectoryClient
{
    public function authenticate(string $username, string $password, array $s): ?array
    {
        $users = is_string($s['fake_users'] ?? null) ? (json_decode($s['fake_users'], true) ?: []) : ($s['fake_users'] ?? []);
        foreach ($users as $u) {
            if (strcasecmp((string) ($u['username'] ?? ''), $username) === 0 && $password !== '' && hash_equals((string) ($u['password'] ?? ''), $password)) {
                return ['subject' => 'fake:'.strtolower($u['username']), 'email' => $u['email'] ?? null, 'name' => $u['name'] ?? null, 'employee_no' => $u['employee_no'] ?? null, 'groups' => $u['groups'] ?? []];
            }
        }

        return null;
    }

    public function ping(array $s): bool
    {
        return true;
    }
}
