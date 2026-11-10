<?php

namespace App\Integrations\Identity;

/** A corporate directory (Active Directory, LDAP) that can check a person's credentials. */
interface DirectoryClient
{
    /** @param  array<string, mixed>  $settings  @return array{subject: string, email: ?string, name: ?string, employee_no: ?string, groups: list<string>}|null null when the credentials are wrong */
    public function authenticate(string $username, string $password, array $settings): ?array;

    /** @param  array<string, mixed>  $settings */
    public function ping(array $settings): bool;
}
