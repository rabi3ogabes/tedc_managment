<?php

namespace Tests\Unit;

use App\Integrations\Identity\LdapDirectory;
use PHPUnit\Framework\TestCase;

/** The directory filter is built without the ldap extension, so its escaping is checked everywhere (RFC 4515). */
class LdapFilterTest extends TestCase
{
    public function test_special_characters_are_escaped(): void
    {
        $this->assertSame('\\2a\\29\\28uid=\\2a\\5c\\00', LdapDirectory::escapeFilter("*)(uid=*\\\x00"));
        $this->assertSame('سارة.علي', LdapDirectory::escapeFilter('سارة.علي'));
    }

    public function test_the_active_accounts_rule_is_added_to_the_user_filter(): void
    {
        $this->assertSame('(&(uid=s\\2a)(!(employeeType=disabled)))', LdapDirectory::userFilter('s*', ['user_filter' => '(uid={username})', 'active_filter' => '(!(employeeType=disabled))']));
        $this->assertSame('(uid=s)', LdapDirectory::userFilter('s', ['user_filter' => '(uid={username})', 'active_filter' => '']));
        // Active Directory by default: sAMAccountName, and disabled accounts left out.
        $this->assertSame('(&(sAMAccountName=sara)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))', LdapDirectory::userFilter('sara', []));
    }

    public function test_a_group_is_also_known_by_its_common_name(): void
    {
        $this->assertSame('trainers', LdapDirectory::cnOf('cn=trainers,ou=groups,dc=tedc,dc=test'));
        $this->assertSame('Ali, Sara', LdapDirectory::cnOf('CN=Ali\\, Sara,OU=Groups,DC=moe,DC=gov,DC=qa'));
        $this->assertSame('Ali, Sara', LdapDirectory::cnOf('CN=Ali\\2C Sara,OU=Groups,DC=moe'));
        $this->assertSame('مدربون', LdapDirectory::cnOf('CN=\\D9\\85\\D8\\AF\\D8\\B1\\D8\\A8\\D9\\88\\D9\\86,OU=Groups'));
        $this->assertNull(LdapDirectory::cnOf('OU=Groups,DC=moe'));
    }

    public function test_groups_are_offered_by_full_name_and_common_name_once_each(): void
    {
        $found = [
            'cn=trainers,ou=groups,dc=moe' => ['CN=Trainers,OU=Groups,DC=moe', 'Trainers'], 'cn=leads,ou=groups,dc=moe' => ['cn=leads,ou=groups,dc=moe', 'leads'],
            'ou=x' => ['OU=x', null], 'cn=trainers,ou=other' => ['cn=TRAINERS,ou=other', 'TRAINERS'],
        ];
        $this->assertSame(['CN=Trainers,OU=Groups,DC=moe', 'cn=leads,ou=groups,dc=moe', 'OU=x', 'cn=TRAINERS,ou=other', 'Trainers', 'leads'], LdapDirectory::groupNames($found));
    }
}
