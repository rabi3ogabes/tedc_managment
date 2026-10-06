<?php

namespace App\Integrations;

/** The systems TEDC talks to, with the settings each needs. Secrets are stored encrypted and never returned to the browser. */
class IntegrationRegistry
{
    /** @return array<string, array{name: array{ar: string, en: string}, drivers: list<string>, group: string, fields: list<array<string, mixed>>}> */
    public static function all(): array
    {
        $fake = fn (string $k) => ['k' => $k, 'type' => 'json', 'hint' => 'Training mode only (driver: fake)'];
        $http = [['k' => 'base_url', 'type' => 'url'], ['k' => 'api_key', 'secret' => true], ['k' => 'health_path', 'default' => '/health'], ['k' => 'inbound_secret', 'secret' => true]];

        return [
            'entra' => ['group' => 'identity', 'name' => ['ar' => 'مايكروسوفت Entra ID (الدخول الموحد)', 'en' => 'Microsoft Entra ID (single sign-on)'], 'drivers' => ['fake', 'oidc'], 'fields' => [
                ['k' => 'tenant_id'], ['k' => 'client_id'], ['k' => 'client_secret', 'secret' => true], ['k' => 'redirect_uri', 'type' => 'url'], ['k' => 'scopes', 'default' => 'openid profile email'],
                ['k' => 'jit', 'type' => 'bool', 'default' => true], ['k' => 'allowed_domains'], ['k' => 'group_map', 'type' => 'json', 'hint' => '[{"group":"<Entra group object id>","role":"employee","scope_type":"school","scope_id":null}]'],
                ['k' => 'break_glass', 'type' => 'bool', 'default' => true],
            ]],
            'ldap' => ['group' => 'identity', 'name' => ['ar' => 'الدليل النشط / LDAP', 'en' => 'Active Directory / LDAP'], 'drivers' => ['fake', 'ldap'], 'fields' => [
                ['k' => 'host'], ['k' => 'port', 'type' => 'number', 'default' => 636], ['k' => 'use_tls', 'type' => 'bool', 'default' => true], ['k' => 'base_dn'], ['k' => 'bind_dn'], ['k' => 'bind_password', 'secret' => true],
                ['k' => 'user_filter', 'default' => '(sAMAccountName={username})'], ['k' => 'email_attr', 'default' => 'mail'], ['k' => 'name_attr', 'default' => 'displayName'], ['k' => 'employee_no_attr', 'default' => 'employeeID'], ['k' => 'group_map', 'type' => 'json'], ['k' => 'fake_users', 'type' => 'json', 'hint' => 'Training mode only (driver: fake)'],
            ]],
            'teams' => ['group' => 'collaboration', 'name' => ['ar' => 'مايكروسوفت Teams (Graph)', 'en' => 'Microsoft Teams (Graph)'], 'drivers' => ['fake', 'graph'], 'fields' => [
                ['k' => 'tenant_id'], ['k' => 'client_id'], ['k' => 'client_secret', 'secret' => true], ['k' => 'organizer_upn'], ['k' => 'lobby', 'default' => 'organization'],
                ['k' => 'auto_meetings', 'type' => 'bool', 'default' => true], ['k' => 'create_teams', 'type' => 'bool', 'default' => false], ['k' => 'min_presence_percent', 'type' => 'number', 'default' => 50],
                ['k' => 'fake_attendance', 'type' => 'json', 'hint' => 'Training mode only (driver: fake)'],
            ]],
            'hr' => ['group' => 'ministry', 'name' => ['ar' => 'نظام الموارد البشرية', 'en' => 'HR system'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [['k' => 'conflict_policy', 'default' => 'hr_wins'], ['k' => 'employees_path', 'default' => '/employees'], $fake('fake_employees')])],
            'mawared' => ['group' => 'ministry', 'name' => ['ar' => 'موارد (الموارد البشرية الحكومية)', 'en' => 'Mawared (government HR)'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [['k' => 'conflict_policy', 'default' => 'hr_wins'], ['k' => 'employees_path', 'default' => '/employees'], $fake('fake_employees')])],
            'licences' => ['group' => 'ministry', 'name' => ['ar' => 'نظام الرخص المهنية', 'en' => 'Professional licences system'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [$fake('fake_licences')])],
            'nsis' => ['group' => 'ministry', 'name' => ['ar' => 'النظام الوطني لمعلومات الطلبة (NSIS)', 'en' => 'National Student Information System (NSIS)'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [$fake('fake_teachers')])],
            'qneds' => ['group' => 'ministry', 'name' => ['ar' => 'النظام الوطني للبيانات التعليمية (QNEDS)', 'en' => 'Qatar National Educational Data System (QNEDS)'], 'drivers' => ['fake', 'http'], 'fields' => $http],
            'saaed' => ['group' => 'ministry', 'name' => ['ar' => 'سعيد (التذاكر)', 'en' => 'Saaed (ticketing)'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [['k' => 'category_map', 'type' => 'json']])],
            'sijil' => ['group' => 'ministry', 'name' => ['ar' => 'سجل (الأرشيف المركزي)', 'en' => 'Sijil (central archive)'], 'drivers' => ['fake', 'http'], 'fields' => array_merge($http, [['k' => 'archive_certificates', 'type' => 'bool', 'default' => true], ['k' => 'archive_records', 'type' => 'bool', 'default' => true]])],
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
