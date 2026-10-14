<?php

namespace App\Integrations;

use App\Integrations\Identity\LdapDirectory;

/** The systems TEDC talks to, with the settings each needs. Secrets are stored encrypted and never returned to the browser. */
class IntegrationRegistry
{
    /** @return array<string, array{name: array{ar: string, en: string}, drivers: list<string>, group: string, fields: list<array<string, mixed>>}> */
    public static function all(): array
    {
        $fake = fn (string $k) => ['k' => $k, 'type' => 'json', 'hint' => 'Training mode only (driver: fake)'];
        // The way into a Ministry system is set by its contract (Contract tab). These are the settings each way can read; a field with "for_auth"
        // only matters when the contract's authentication is one of those types.
        $http = [
            ['k' => 'base_url', 'type' => 'url'], ['k' => 'api_key', 'secret' => true], ['k' => 'health_path', 'default' => '/health'], ['k' => 'inbound_secret', 'secret' => true],
            ['k' => 'auth_header', 'for_auth' => ['api_key'], 'hint' => 'The header (or parameter) the key travels in, when the contract leaves it to the settings'],
            ['k' => 'username', 'for_auth' => ['basic']], ['k' => 'password', 'secret' => true, 'for_auth' => ['basic']],
            ['k' => 'token_url', 'type' => 'url', 'for_auth' => ['oauth2'], 'hint' => 'The address that issues access tokens (client credentials)'], ['k' => 'client_id', 'for_auth' => ['oauth2']], ['k' => 'client_secret', 'secret' => true, 'for_auth' => ['oauth2']],
            ['k' => 'signing_secret', 'secret' => true, 'for_auth' => ['hmac'], 'hint' => 'Signs every request'],
            ['k' => 'client_certificate', 'secret' => true, 'type' => 'text', 'for_auth' => ['mtls'], 'hint' => 'PEM'], ['k' => 'client_key', 'secret' => true, 'type' => 'text', 'for_auth' => ['mtls'], 'hint' => 'PEM'],
        ];

        // What an approved profile change does while HR wins: it goes to HR instead of being written in TEDC (CAR-05).
        $hrRouting = [
            ['k' => 'route_profile_changes', 'type' => 'bool', 'default' => true, 'hint' => 'An approved change to something HR masters is sent to HR; HR confirms it and TEDC shows it after that'],
            ['k' => 'hr_mastered_fields', 'type' => 'json', 'default' => ['name_ar', 'name_en', 'job_title', 'grade_level', 'qualification', 'specialization', 'hire_date', 'experience_years'], 'hint' => 'What HR masters while it wins: read-only in TEDC, changed through HR. Keys: name_ar, name_en, job_title, school, department, grade_level, education_stage, qualification, specialization, hire_date, experience_years'],
        ];

        return [
            'entra' => ['group' => 'identity', 'name' => ['ar' => 'مايكروسوفت Entra ID (الدخول الموحد)', 'en' => 'Microsoft Entra ID (single sign-on)'], 'drivers' => ['fake', 'oidc'], 'fields' => [
                ['k' => 'tenant_id'], ['k' => 'client_id'], ['k' => 'client_secret', 'secret' => true], ['k' => 'redirect_uri', 'type' => 'url'], ['k' => 'scopes', 'default' => 'openid profile email'],
                ['k' => 'jit', 'type' => 'bool', 'default' => true], ['k' => 'allowed_domains'], ['k' => 'group_map', 'type' => 'json', 'hint' => '[{"group":"<Entra group object id>","role":"employee","scope_type":"school","scope_id":null}]'],
                ['k' => 'sso_mandatory', 'type' => 'bool', 'default' => false, 'hint' => 'Ministry staff (the allowed domains; everyone when none are listed) must use single sign-on. Outside accounts and the super administrator (break-glass) keep their password'],
            ]],
            // SAML 2.0 beside OpenID Connect (NFR-07). The SP key pair is generated and rotated from Settings → Single sign-on.
            'saml' => ['group' => 'identity', 'name' => ['ar' => 'الدخول الموحد عبر SAML 2.0', 'en' => 'Single sign-on with SAML 2.0'], 'drivers' => ['saml'], 'fields' => [
                ['k' => 'idp_metadata_url', 'type' => 'url'], ['k' => 'idp_entity_id'], ['k' => 'idp_sso_url', 'type' => 'url'], ['k' => 'idp_slo_url', 'type' => 'url'],
                ['k' => 'idp_certificates', 'type' => 'text', 'hint' => 'PEM; more than one during a key rollover'], ['k' => 'sp_entity_id', 'hint' => 'Empty: the metadata address'],
                ['k' => 'want_encrypted', 'type' => 'bool', 'default' => false], ['k' => 'jit', 'type' => 'bool', 'default' => true], ['k' => 'allowed_domains'],
                ['k' => 'group_map', 'type' => 'json', 'hint' => '[{"group":"<group id or name>","role":"employee","scope_type":"school","scope_id":null}]'], ['k' => 'sso_mandatory', 'type' => 'bool', 'default' => false, 'hint' => 'Ministry staff (the allowed domains; everyone when none are listed) must use single sign-on. Outside accounts and the super administrator (break-glass) keep their password'],
                ['k' => 'attr_object_id', 'default' => 'http://schemas.microsoft.com/identity/claims/objectidentifier'], ['k' => 'attr_email', 'default' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'],
                ['k' => 'attr_upn', 'default' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name'], ['k' => 'attr_name', 'default' => 'http://schemas.microsoft.com/identity/claims/displayname'],
                ['k' => 'attr_name_ar'], ['k' => 'attr_employee_no', 'default' => 'employeeid'], ['k' => 'attr_groups', 'default' => 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups'],
                ['k' => 'sp_private_key', 'secret' => true], ['k' => 'sp_certificate', 'type' => 'text'], ['k' => 'sp_next_private_key', 'secret' => true], ['k' => 'sp_next_certificate', 'type' => 'text'],
            ]],
            'ldap' => ['group' => 'identity', 'name' => ['ar' => 'الدليل النشط / LDAP', 'en' => 'Active Directory / LDAP'], 'drivers' => ['fake', 'ldap'], 'fields' => [
                ['k' => 'host'], ['k' => 'port', 'type' => 'number', 'default' => 636], ['k' => 'use_tls', 'type' => 'bool', 'default' => true], ['k' => 'base_dn'], ['k' => 'bind_dn'], ['k' => 'bind_password', 'secret' => true],
                ['k' => 'user_filter', 'default' => '(sAMAccountName={username})'], ['k' => 'active_filter', 'default' => LdapDirectory::AD_ACTIVE, 'hint' => 'Only accounts matching this sign in (Active Directory: not disabled). Empty: no rule'],
                ['k' => 'email_attr', 'default' => 'mail'], ['k' => 'name_attr', 'default' => 'displayName'], ['k' => 'employee_no_attr', 'default' => 'employeeID'],
                ['k' => 'nested_groups', 'default' => 'recursive', 'options' => ['recursive', 'in_chain', 'direct'], 'hint' => 'recursive: groups inside groups · in_chain: Active Directory transitive search · direct: own groups only'],
                ['k' => 'groups_base_dn', 'hint' => 'Where groups are searched (the base DN when empty)'], ['k' => 'allowed_domains', 'hint' => 'E-mail domains this directory speaks for (an existing account with such an e-mail is linked)'], ['k' => 'group_map', 'type' => 'json', 'hint' => 'Map a group by its full name (DN) or its common name (CN)'],
                ['k' => 'timeout', 'type' => 'number', 'default' => 8, 'hint' => 'Seconds'], ['k' => 'ca_certificate', 'type' => 'text', 'hint' => 'The directory certificate authority (PEM), when the server does not already trust it'],
                ['k' => 'fake_users', 'type' => 'json', 'hint' => 'Training mode only (driver: fake)'],
            ]],
            'teams' => ['group' => 'collaboration', 'name' => ['ar' => 'مايكروسوفت Teams (Graph)', 'en' => 'Microsoft Teams (Graph)'], 'drivers' => ['fake', 'graph'], 'fields' => [
                ['k' => 'tenant_id'], ['k' => 'client_id'], ['k' => 'client_secret', 'secret' => true], ['k' => 'organizer_upn'], ['k' => 'lobby', 'default' => 'organization'],
                ['k' => 'auto_meetings', 'type' => 'bool', 'default' => true], ['k' => 'create_teams', 'type' => 'bool', 'default' => false], ['k' => 'min_presence_percent', 'type' => 'number', 'default' => 50],
                ['k' => 'townhalls', 'type' => 'bool', 'default' => false, 'hint' => 'Sessions set to "Teams town hall" get a town hall (needs the connected organiser account)'],
                ['k' => 'townhall_invite_only', 'type' => 'bool', 'default' => false, 'hint' => 'Only the registered trainees can join'],
                ['k' => 'organizer_account', 'hint' => 'Set by "Connect the organiser account"'], ['k' => 'organizer_refresh_token', 'secret' => true, 'hint' => 'Set by "Connect the organiser account"'],
                ['k' => 'activity_feed', 'type' => 'bool', 'default' => false, 'hint' => 'TEDC notifications in the Teams activity feed (needs the TEDC Teams app installed)'],
                ['k' => 'teams_app_id', 'hint' => 'The TEDC Teams app id (made with the app package)'],
                ['k' => 'fake_attendance', 'type' => 'json', 'hint' => 'Training mode only (driver: fake)'], ['k' => 'fake_permissions', 'type' => 'json', 'hint' => 'Training mode only (driver: fake)'],
            ]],
            'hr' => ['group' => 'ministry', 'name' => ['ar' => 'نظام الموارد البشرية', 'en' => 'HR system'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [['k' => 'conflict_policy', 'default' => 'hr_wins'], ['k' => 'employees_path', 'default' => '/employees'], ...$hrRouting, $fake('fake_employees')])],
            'mawared' => ['group' => 'ministry', 'name' => ['ar' => 'موارد (الموارد البشرية الحكومية)', 'en' => 'Mawared (government HR)'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [['k' => 'conflict_policy', 'default' => 'hr_wins'], ['k' => 'employees_path', 'default' => '/employees'], ...$hrRouting, $fake('fake_employees')])],
            'licences' => ['group' => 'ministry', 'name' => ['ar' => 'نظام الرخص المهنية', 'en' => 'Professional licences system'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [$fake('fake_licences')])],
            'nsis' => ['group' => 'ministry', 'name' => ['ar' => 'النظام الوطني لمعلومات الطلبة (NSIS)', 'en' => 'National Student Information System (NSIS)'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [$fake('fake_teachers')])],
            'qneds' => ['group' => 'ministry', 'name' => ['ar' => 'النظام الوطني للبيانات التعليمية (QNEDS)', 'en' => 'Qatar National Educational Data System (QNEDS)'], 'drivers' => ['fake', 'http'], 'fields' => $http],
            'saaed' => ['group' => 'ministry', 'name' => ['ar' => 'سعيد (التذاكر)', 'en' => 'Saaed (ticketing)'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [['k' => 'category_map', 'type' => 'json']])],
            'sijil' => ['group' => 'ministry', 'name' => ['ar' => 'سجل (الأرشيف المركزي)', 'en' => 'Sijil (central archive)'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [['k' => 'archive_certificates', 'type' => 'bool', 'default' => true], ['k' => 'archive_records', 'type' => 'bool', 'default' => true]])],
            'siem' => ['group' => 'ministry', 'name' => ['ar' => 'مركز المراقبة الأمنية (SIEM)', 'en' => 'Security monitoring (SIEM)'], 'drivers' => ['fake', 'splunk_hec', 'log_analytics'], 'fields' => [['k' => 'url', 'type' => 'url', 'hint' => 'Splunk HEC base URL'], ['k' => 'token', 'secret' => true, 'hint' => 'Splunk HEC token'], ['k' => 'index'], ['k' => 'sourcetype', 'default' => '_json'],
                ['k' => 'tenant_id', 'hint' => 'Sentinel / Log Analytics'], ['k' => 'client_id'], ['k' => 'client_secret', 'secret' => true], ['k' => 'dce_endpoint', 'type' => 'url'], ['k' => 'dcr_id'], ['k' => 'stream_name', 'default' => 'Custom-TedcSecurity_CL']]],
            'moe_epay' => ['group' => 'ministry', 'name' => ['ar' => 'بوابة الدفع الإلكتروني للوزارة', 'en' => 'Ministry e-payment gateway'], 'drivers' => ['fake', 'http'], 'fields' => [['k' => 'base_url', 'type' => 'url'], ['k' => 'merchant_id'], ['k' => 'secret', 'secret' => true], ['k' => 'health_path', 'default' => '/health']]],
            'ministry_site' => ['group' => 'ministry', 'name' => ['ar' => 'موقع الوزارة', 'en' => 'Ministry website'], 'drivers' => ['http'], 'fields' => [], 'managed_elsewhere' => 'Communication → Ministry website'],
            'hudhud' => ['group' => 'ministry', 'name' => ['ar' => 'هدهد (الرسائل النصية)', 'en' => 'Hudhud (SMS)'], 'drivers' => ['http'], 'fields' => [], 'managed_elsewhere' => 'Settings → Notification channels'],
        ];
    }

    /** @return list<string> */
    public static function secretFields(string $key): array
    {
        return collect(self::all()[$key]['fields'] ?? [])->filter(fn ($f) => ! empty($f['secret']))->pluck('k')->all();
    }
}
