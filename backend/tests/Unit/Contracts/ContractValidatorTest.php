<?php

namespace Tests\Unit\Contracts;

use App\Integrations\Contracts\ContractValidator;
use PHPUnit\Framework\TestCase;

/** A contract an administrator saves is data that is checked first: it cannot change the host, read a secret, or run anything. */
class ContractValidatorTest extends TestCase
{
    private const SECRETS = ['api_key', 'client_secret', 'inbound_secret'];

    private function contract(array $patch = []): array
    {
        $doc = [
            'system' => 'hr', 'version' => '1.0',
            'auth' => ['type' => 'bearer', 'setting' => 'api_key'],
            'operations' => [
                'fetch_employees' => [
                    'method' => 'GET', 'path' => '/employees', 'query' => ['since?' => '{{ since }}', 'page?' => '{{ page }}'], 'safe' => true,
                    'response' => ['records' => '$.data', 'next_page' => '$.meta.next_page'], 'pagination' => ['type' => 'page', 'param' => 'page', 'start' => 1, 'max_pages' => 50],
                    'errors' => ['404' => ['code' => 'not_found', 'message' => ['ar' => 'غير موجود', 'en' => 'Not found']]],
                ],
                'push_items' => ['method' => 'POST', 'path' => '/items/{{ year|urlencode }}', 'body' => ['format' => 'json', 'template' => ['year' => '{{ year }}', 'items' => '{{ items }}']], 'idempotency' => ['header' => 'Idempotency-Key']],
            ],
        ];

        return array_replace_recursive($doc, $patch);
    }

    public function test_a_sound_contract_has_no_problems(): void
    {
        $this->assertSame([], ContractValidator::problems('hr', 'contract', $this->contract(), self::SECRETS));
    }

    public function test_the_host_can_never_be_changed_by_a_contract(): void
    {
        foreach (['https://evil.example/x', '//evil.example/x', 'employees', '/a/../../etc', '/x?y=1', '/x#frag', "/x\n/y", '/ x'] as $path) {
            $doc = $this->contract();
            $doc['operations']['fetch_employees']['path'] = $path;
            $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS), $path);
        }
    }

    public function test_a_path_may_come_from_a_setting_of_the_system(): void
    {
        $doc = $this->contract();
        $doc['operations']['fetch_employees']['path'] = '{{ settings.employees_path|default:/employees|raw }}';
        $this->assertSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS));
        $doc['operations']['fetch_employees']['path'] = '{{ since|raw }}';
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS), 'a request value cannot make the path');
        $doc['operations']['fetch_employees']['path'] = '{{ settings.api_key|raw }}';
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS), 'nor a secret');
    }

    public function test_the_api_key_header_may_be_named_by_a_setting_and_a_value_may_have_fallback_paths(): void
    {
        $doc = $this->contract(['auth' => ['type' => 'api_key', 'in' => 'header', 'name_setting' => 'auth_header', 'setting' => 'api_key']]);
        $this->assertSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS));
        $doc = $this->contract(['operations' => ['fetch_employees' => ['response' => ['values' => ['ticket' => ['$.ticket_no', '$.id']]]]]]);
        $this->assertSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS));
        $doc = $this->contract(['operations' => ['fetch_employees' => ['response' => ['values' => ['ticket' => ['$.ticket_no', '$..id']]]]]]);
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS));
    }

    public function test_the_settings_a_contract_names_must_exist_on_the_system(): void
    {
        $known = ['api_key', 'base_url', 'employees_path', 'auth_header', 'inbound_secret'];
        $check = fn (array $doc) => ContractValidator::problems('hr', 'contract', $doc, self::SECRETS, $known);
        $this->assertSame([], $check($this->contract()));
        $this->assertNotSame([], $check($this->contract(['auth' => ['type' => 'bearer', 'setting' => 'apikey']])), 'a mistyped credential setting');
        $this->assertNotSame([], $check($this->contract(['auth' => ['type' => 'api_key', 'name' => 'X-Key', 'setting' => 'api_key', 'name_setting' => 'header_name']])));
        $doc = $this->contract();
        $doc['operations']['fetch_employees']['path'] = '{{ settings.employee_path|default:/employees|raw }}';
        $this->assertNotSame([], $check($doc), 'a mistyped path setting');
        $this->assertSame([], ContractValidator::problems('hr', 'contract', $this->contract(['auth' => ['type' => 'bearer', 'setting' => 'whatever']]), self::SECRETS), 'nothing is checked without the list');
    }

    public function test_authentication_may_be_optional_for_a_system_that_relies_on_the_network(): void
    {
        $this->assertSame([], ContractValidator::problems('hr', 'contract', $this->contract(['auth' => ['type' => 'bearer', 'setting' => 'api_key', 'optional' => true]]), self::SECRETS));
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $this->contract(['auth' => ['type' => 'bearer', 'setting' => 'api_key', 'optional' => 'yes']]), self::SECRETS));
    }

    public function test_a_test_case_names_a_recorded_answer_or_carries_its_own(): void
    {
        $case = fn (array $extra) => $this->contract(['tests' => [['name' => 'a case', 'operation' => 'fetch_employees'] + $extra]]);
        $check = fn (array $extra) => ContractValidator::problems('hr', 'contract', $case($extra), self::SECRETS);
        $this->assertSame([], $check([]), 'a case that only checks the request');
        $this->assertSame([], $check(['fixture' => 'fetch_employees.ok']));
        $this->assertSame([], $check(['response' => ['status' => 200, 'body' => ['data' => []]]]), 'a sample the Ministry sent, pasted in');
        $this->assertNotSame([], $check(['fixture' => '../../.env']));
        $this->assertNotSame([], $check(['fixture' => 'Fetch Employees']));
        $this->assertNotSame([], $check(['response' => ['status' => 'ok', 'body' => []]]));
        $this->assertNotSame([], $check(['response' => ['status' => 42, 'body' => []]]));
        $this->assertNotSame([], $check(['response' => 'text']));
    }

    public function test_success_rules_and_a_fallback_credential_are_checked(): void
    {
        $rule = fn ($rule) => ContractValidator::problems('hr', 'contract', $this->contract(['operations' => ['fetch_employees' => ['success_when' => $rule]]]), self::SECRETS);
        $this->assertSame([], $rule(['path' => '$.status', 'not_in' => ['failed']]));
        $this->assertSame([], $rule([['path' => '$.a', 'equals' => 1], ['body_contains' => 'OK']]));
        $this->assertNotSame([], $rule(['path' => '$..status', 'equals' => 'x']), 'a path outside the subset');
        $this->assertNotSame([], $rule(['equals' => 'x']), 'a comparison with nothing to compare');
        $this->assertNotSame([], $rule([]), 'a rule that says nothing');
        $this->assertNotSame([], $rule(['path' => '$.a']), 'a path without a condition');
        $this->assertNotSame([], $rule(['path' => '$.a', 'in' => 'x']), '"in" is a list');
        $this->assertNotSame([], $rule(['body_contains' => str_repeat('x', 300)]));

        $auth = fn ($fallback) => ContractValidator::problems('hr', 'contract', $this->contract(['auth' => ['type' => 'bearer', 'setting' => 'api_key', 'fallback' => $fallback]]), self::SECRETS);
        $this->assertSame([], $auth(['type' => 'basic', 'user_setting' => 'username', 'password_setting' => 'password']));
        $this->assertNotSame([], $auth(['type' => 'basic']), 'a fallback needs what its type needs');
        $this->assertNotSame([], $auth(['type' => 'hmac', 'secret_setting' => 'x', 'signature_header' => 'X-S']), 'only a simple credential can be a fallback');
        $this->assertNotSame([], $auth(['type' => 'bearer', 'setting' => 'x', 'fallback' => ['type' => 'basic', 'user_setting' => 'u', 'password_setting' => 'p']]), 'no fallback of a fallback');
    }

    public function test_methods_auth_types_and_pagination_are_checked(): void
    {
        $bad = fn (array $patch) => ContractValidator::problems('hr', 'contract', $this->contract($patch), self::SECRETS);
        $this->assertNotSame([], $bad(['operations' => ['fetch_employees' => ['method' => 'TRACE']]]));
        $this->assertNotSame([], $bad(['auth' => ['type' => 'magic']]));
        $this->assertNotSame([], $bad(['operations' => ['fetch_employees' => ['pagination' => ['type' => 'infinite']]]]));
        $this->assertNotSame([], $bad(['operations' => ['fetch_employees' => ['pagination' => ['max_pages' => 100000]]]]));
        $this->assertNotSame([], $bad(['operations' => ['fetch_employees' => ['response' => ['records' => '$..deep']]]]));
        $this->assertNotSame([], $bad(['system' => 'someone-else']));
        $this->assertNotSame([], $bad(['auth' => ['type' => 'hmac', 'secret_setting' => 'inbound_secret', 'algorithm' => 'md5']]));
        $this->assertNotSame([], $bad(['auth' => ['type' => 'bearer', 'setting' => 'Bad Name!']]));
    }

    public function test_oauth2_may_name_its_token_address_its_client_authentication_extra_fields_and_scheme(): void
    {
        $problems = fn (array $auth) => ContractValidator::problems('hr', 'contract', $this->contract(['auth' => ['type' => 'oauth2', 'client_id_setting' => 'client_id', 'client_secret_setting' => 'client_secret'] + $auth]), self::SECRETS);
        $this->assertSame([], $problems(['token_url' => 'https://auth.example.com/oauth2/token']));
        $this->assertSame([], $problems(['token_path' => '/oauth2/access_token', 'client_auth' => 'basic', 'token_params' => ['token_type' => 'jwt'], 'header_scheme' => 'JWT']));
        $this->assertSame([], $problems(['token_url_setting' => 'token_url']), 'the address in a setting, as before');
        $this->assertSame([], $problems(['token_url' => 'https://auth.example.com/t', 'token_url_setting' => 'token_url']), 'a fixed address a setting may override');
        $this->assertNotSame([], $problems([]), 'a token address is needed');
        foreach ([['token_url' => 'http://auth.example.com/t'], ['token_url' => 'https://user:pw@auth.example.com/t'], ['token_url' => 'https://auth.example.com/t#x'], ['token_url' => '//auth.example.com/t'],
            ['token_url' => 'https://auth.example.com/t?a=1'], ['token_path' => 'oauth2/token'], ['token_path' => 'https://x.example.com/y'], ['token_path' => '/a/../b'],
            ['token_url' => 'https://a.example.com/t', 'token_path' => '/x'], ['token_url' => 'https://a.example.com/t', 'client_auth' => 'header'],
            ['token_url' => 'https://a.example.com/t', 'token_params' => ['a' => ['nested']]], ['token_url' => 'https://a.example.com/t', 'token_params' => ['client_secret' => 'x']],
            ['token_url' => 'https://a.example.com/t', 'token_params' => ['grant_type' => 'password']], ['token_url' => 'https://a.example.com/t', 'token_params' => ['Bad Name' => 'x']],
            ['token_url' => 'https://a.example.com/t', 'header_scheme' => 'Bearer token'], ['token_url' => 'https://a.example.com/t', 'header_scheme' => "x\r\nInjected: 1"]] as $bad) {
            $this->assertNotSame([], $problems($bad), json_encode($bad));
        }
    }

    public function test_the_address_may_be_made_from_a_name_but_only_under_a_domain_the_contract_fixes(): void
    {
        $problems = fn ($base) => ContractValidator::problems('hr', 'contract', $this->contract(['base_url' => $base]), self::SECRETS);
        $this->assertSame([], $problems(['pattern' => 'https://{subdomain}.udemy.com', 'variables' => ['subdomain' => '^[a-z0-9][a-z0-9-]{0,62}$']]));
        $this->assertSame([], $problems(['pattern' => 'https://{tenant}.example.org/api', 'variables' => ['tenant' => '^[a-z]+$']]));
        foreach ([['pattern' => 'http://{s}.udemy.com', 'variables' => ['s' => '^[a-z]+$']], ['pattern' => 'https://{s}', 'variables' => ['s' => '^[a-z]+$']], ['pattern' => 'https://{s}.{t}', 'variables' => ['s' => '^[a-z]+$', 't' => '^[a-z]+$']],
            ['pattern' => 'https://{s}.com', 'variables' => ['s' => '^[a-z]+$']], ['pattern' => 'https://udemy.com@{s}.evil.example', 'variables' => ['s' => '^[a-z]+$']], ['pattern' => 'https://{s}.udemy.com:8443', 'variables' => ['s' => '^[a-z]+$']],
            ['pattern' => 'https://{s}.udemy.com/{s}', 'variables' => ['s' => '^[a-z]+$']], ['pattern' => 'https://{s}.udemy.com?x=1', 'variables' => ['s' => '^[a-z]+$']], ['pattern' => 'https://{s}.udemy.com', 'variables' => []],
            ['pattern' => 'https://{s}.udemy.com', 'variables' => ['s' => '[a-z']], ['pattern' => 'https://{s}.udemy.com', 'variables' => ['s' => '^[a-z]+$', 'extra' => '^x$']], ['pattern' => 'https://{s}.udemy.com'], 'https://x.example.org'] as $bad) {
            $this->assertNotSame([], $problems($bad), json_encode($bad));
        }
    }

    public function test_a_test_case_may_carry_plain_settings_but_never_a_secret(): void
    {
        $case = fn ($settings) => ContractValidator::problems('hr', 'contract', $this->contract(['tests' => [['name' => 'a case', 'operation' => 'fetch_employees', 'settings' => $settings]]]), self::SECRETS);
        $this->assertSame([], $case(['org_id' => 'ORG-1', 'username' => 'service']));
        $this->assertNotSame([], $case('text'));
        $this->assertNotSame([], $case(['api_key' => 'X']), 'a secret does not belong in a document');
        $this->assertNotSame([], $case(['org_id' => ['nested']]));
        $this->assertNotSame([], $case(['Bad Name' => 'x']));
        $this->assertNotSame([], $case(['org_id' => str_repeat('x', 300)]));
    }

    public function test_an_operation_may_say_how_long_a_stretch_of_time_one_call_can_ask_for(): void
    {
        $windows = fn ($w) => ContractValidator::problems('hr', 'contract', $this->contract(['operations' => ['fetch_employees' => ['windows' => $w]]]), self::SECRETS);
        $this->assertSame([], $windows(['max_days' => 14]));
        $this->assertSame([], $windows(['max_days' => 366]));
        foreach ([['max_days' => 0], ['max_days' => 400], ['max_days' => '14'], ['max_days' => 1.5], [], 'text', [14]] as $bad) {
            $this->assertNotSame([], $windows($bad), json_encode($bad));
        }
    }

    public function test_templates_cannot_read_secrets_or_use_unknown_filters(): void
    {
        $doc = $this->contract();
        $doc['operations']['fetch_employees']['query'] = ['k' => '{{ settings.api_key }}'];
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS));
        $doc['operations']['fetch_employees']['query'] = ['k' => '{{ secrets.api_key }}'];
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS));
        $doc['operations']['fetch_employees']['query'] = ['k' => '{{ a|eval }}'];
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS));
        $doc['operations']['fetch_employees']['query'] = ['k' => '{{ settings.base_url }}'];
        $this->assertSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS), 'a setting that is not a secret may be read');
    }

    public function test_headers_cannot_carry_credentials_or_break_the_message(): void
    {
        foreach (['Authorization', 'authorization', 'Host', 'Content-Length', 'Transfer-Encoding', 'Cookie', "X-Evil\r\nInjected", 'Bad Name'] as $h) {
            $doc = $this->contract();
            $doc['operations']['fetch_employees']['headers'] = [$h => 'x'];
            $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS), $h);
        }
        $doc = $this->contract();
        $doc['operations']['fetch_employees']['headers'] = ['Accept-Language' => 'ar', 'X-Request-Source' => 'tedc'];
        $this->assertSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS));
    }

    public function test_an_operation_needs_what_it_needs_and_the_document_stays_small(): void
    {
        $doc = $this->contract();
        unset($doc['operations']['fetch_employees']['path']);
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $doc, self::SECRETS));
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', ['system' => 'hr', 'operations' => []], self::SECRETS));
        $big = $this->contract();
        $big['operations']['fetch_employees']['response_schema'] = ['description' => str_repeat('x', 300_000)];
        $this->assertNotSame([], ContractValidator::problems('hr', 'contract', $big, self::SECRETS));
    }

    public function test_a_mapping_is_checked_entity_by_entity(): void
    {
        $ok = ['version' => 1, 'entities' => ['employee' => ['fields' => ['employee_no' => '$.no']]]];
        $this->assertSame([], ContractValidator::problems('hr', 'mapping', $ok, self::SECRETS));
        $bad = ['entities' => ['employee' => ['fields' => ['employee_no' => '$..no']]]];
        $this->assertNotSame([], ContractValidator::problems('hr', 'mapping', $bad, self::SECRETS));
        $this->assertNotSame([], ContractValidator::problems('hr', 'mapping', ['entities' => []], self::SECRETS));
        $this->assertNotSame([], ContractValidator::problems('hr', 'nonsense', $ok, self::SECRETS));
    }
}
