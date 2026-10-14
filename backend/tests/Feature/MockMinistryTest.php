<?php

namespace Tests\Feature;

use App\Integrations\Contracts\Contract;
use App\Integrations\Contracts\ContractDriver;
use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\MockMinistry;
use Tests\TestCase;

/**
 * The local stand-in for a Ministry system: it answers the contract's operations from the recorded fixtures (including errors and slow
 * answers), so UAT, demos and the contract tests can run before the real system is available.
 */
class MockMinistryTest extends TestCase
{
    private function standIn(string $system = 'hr', string $scenario = 'ok', ?string $key = null): MockMinistry
    {
        return MockMinistry::fromDirectory(resource_path('contracts/'.(ContractStore::ALIASES[$system] ?? $system)), $system, $scenario, $key);
    }

    private function ask(MockMinistry $m, string $path, array $headers = ['authorization' => 'Bearer any-key'], string $method = 'GET'): array
    {
        return $m->respond($method, $path, [], $headers, '');
    }

    public function test_it_answers_an_operation_from_its_fixture(): void
    {
        $res = $this->ask($this->standIn(), '/employees');
        $this->assertSame(200, $res['status']);
        $this->assertSame('E-1001', json_decode($res['body'], true)['data'][0]['employee_no']);
        $this->assertSame('application/json', $res['headers']['Content-Type']);
        $this->assertSame(0, $res['delay_ms']);
    }

    public function test_a_path_with_a_variable_part_is_matched(): void
    {
        $res = $this->ask($this->standIn('saaed'), '/tickets/SAEED-0001');
        $this->assertSame(200, $res['status']);
        $this->assertSame('in_progress', json_decode($res['body'], true)['status']);
    }

    public function test_a_scenario_gives_an_error_or_a_slow_answer(): void
    {
        $m = $this->standIn();
        $this->assertSame(500, $m->respond('GET', '/employees', [], ['authorization' => 'Bearer k', 'x-mock-scenario' => 'server_error'], '')['status']);
        $this->assertSame(500, $m->respond('GET', '/employees', ['_scenario' => 'server_error'], ['authorization' => 'Bearer k'], '')['status']);
        $this->assertSame(500, $this->ask($this->standIn('hr', 'server_error'), '/employees')['status'], 'the scenario the server was started with');
        $slow = $this->ask($this->standIn('hr', 'slow'), '/employees');
        $this->assertSame([200, 3000], [$slow['status'], $slow['delay_ms']]);
    }

    public function test_an_unknown_path_a_wrong_method_and_a_missing_fixture_are_explained(): void
    {
        $m = $this->standIn();
        $res = $this->ask($m, '/nothing-here');
        $this->assertSame(404, $res['status']);
        $this->assertStringContainsString('/employees', $res['body'], 'the answer lists what the mock does know');
        $this->assertSame(405, $this->ask($m, '/employees', ['authorization' => 'Bearer k'], 'DELETE')['status']);
        $res = $m->respond('GET', '/employees', ['_scenario' => 'no_such_one'], ['authorization' => 'Bearer k'], '');
        $this->assertSame(501, $res['status']);
        $this->assertStringContainsString('fetch_employees.no_such_one', $res['body']);
    }

    public function test_the_contracts_authentication_is_required(): void
    {
        $m = $this->standIn();
        $this->assertSame(401, $m->respond('GET', '/employees', [], [], '')['status'], 'no key');
        $this->assertSame(401, $m->respond('GET', '/employees', [], ['authorization' => 'Basic abc'], '')['status'], 'the wrong scheme');
        $this->assertSame(200, $this->standIn('hr', 'ok', 'secret-key')->respond('GET', '/employees', [], ['authorization' => 'Bearer secret-key'], '')['status']);
        $this->assertSame(401, $this->standIn('hr', 'ok', 'secret-key')->respond('GET', '/employees', [], ['authorization' => 'Bearer wrong'], '')['status'], 'the key the server expects');
        $this->assertSame(200, $this->standIn('ministry_site')->respond('POST', '/', [], ['x-api-key' => 'k'], '{}')['status'], 'an API key travels in the header the contract names');
        $this->assertSame(401, $this->standIn('ministry_site')->respond('POST', '/', [], [], '{}')['status']);
    }

    /** @return array<string, mixed> a small system that signs in with OAuth2 client credentials (as the learning providers do) */
    private function oauthDirectory(array $auth): string
    {
        $dir = sys_get_temp_dir().'/mock-oauth-'.bin2hex(random_bytes(4));
        mkdir($dir.'/fixtures', 0777, true);
        file_put_contents($dir.'/contract.json', json_encode(['system' => 'prov', 'version' => '1.0', 'auth' => $auth + ['type' => 'oauth2', 'client_id_setting' => 'client_id', 'client_secret_setting' => 'client_secret'],
            'operations' => ['list' => ['method' => 'GET', 'path' => '/api/orgs/{{ settings.org_id }}/items', 'safe' => true, 'response' => ['records' => '$.elements']]]]));
        file_put_contents($dir.'/mapping.json', '{}');
        file_put_contents($dir.'/fixtures/list.ok.json', json_encode(['status' => 200, 'body' => ['elements' => [['id' => 1]]]]));

        return $dir;
    }

    public function test_a_path_with_a_setting_that_is_not_given_matches_any_value(): void
    {
        $m = MockMinistry::fromDirectory($this->oauthDirectory(['token_path' => '/oauth2/token']), 'prov');
        $this->assertSame(200, $m->respond('GET', '/api/orgs/ANY-ORG/items', [], ['authorization' => 'Bearer t'], '')['status']);
        $given = $m->withSettings(['org_id' => 'ORG-1']);
        $this->assertSame(200, $given->respond('GET', '/api/orgs/ORG-1/items', [], ['authorization' => 'Bearer t'], '')['status']);
        $this->assertSame(404, $given->respond('GET', '/api/orgs/ORG-2/items', [], ['authorization' => 'Bearer t'], '')['status'], 'a setting that is given is the address');
    }

    public function test_it_issues_a_token_where_the_contract_says_and_wants_the_client_in_the_way_the_contract_says(): void
    {
        $m = MockMinistry::fromDirectory($this->oauthDirectory(['token_path' => '/oauth2/access_token', 'client_auth' => 'basic', 'token_params' => ['token_type' => 'jwt'], 'header_scheme' => 'JWT']), 'prov');
        $basic = ['authorization' => 'Basic '.base64_encode('id:secret'), 'content-type' => 'application/x-www-form-urlencoded'];
        $ok = $m->respond('POST', '/oauth2/access_token', [], $basic, 'grant_type=client_credentials&token_type=jwt');
        $this->assertSame(200, $ok['status']);
        $this->assertSame('JWT', json_decode($ok['body'], true)['token_type']);
        $this->assertNotSame('', json_decode($ok['body'], true)['access_token']);
        $this->assertSame(401, $m->respond('POST', '/oauth2/access_token', [], ['content-type' => 'application/x-www-form-urlencoded'], 'grant_type=client_credentials&client_id=id&client_secret=secret')['status'], 'this contract wants the client in the header');
        $this->assertSame(400, $m->respond('POST', '/oauth2/access_token', [], $basic, 'grant_type=password')['status'], 'only client credentials');
        $this->assertSame(401, $this->ask($m, '/api/orgs/O/items', ['authorization' => 'Bearer t'])['status'], 'the scheme the contract names is the one it accepts');
        $this->assertSame(200, $this->ask($m, '/api/orgs/O/items', ['authorization' => 'JWT t'])['status']);

        $form = MockMinistry::fromDirectory($this->oauthDirectory(['token_path' => '/oauth2/token']), 'prov');
        $this->assertSame(200, $form->respond('POST', '/oauth2/token', [], ['content-type' => 'application/x-www-form-urlencoded'], 'grant_type=client_credentials&client_id=id&client_secret=secret')['status']);
        $this->assertSame(401, $form->respond('POST', '/oauth2/token', [], ['content-type' => 'application/x-www-form-urlencoded'], 'grant_type=client_credentials')['status'], 'no client, no token');
        $this->assertSame('Bearer', json_decode($form->respond('POST', '/oauth2/token', [], [], 'grant_type=client_credentials&client_id=id&client_secret=secret')['body'], true)['token_type']);
    }

    public function test_the_address_of_the_token_service_is_known_to_the_mock(): void
    {
        $fixed = MockMinistry::fromDirectory($this->oauthDirectory(['token_url' => 'https://auth.provider.test/oauth2/client_credentials/token', 'client_auth' => 'basic']), 'prov');
        $this->assertSame('https://auth.provider.test/oauth2/client_credentials/token', $fixed->tokenUrl('https://sys.test/ent'), 'a token service of its own, beside the system');
        $relative = MockMinistry::fromDirectory($this->oauthDirectory(['token_path' => '/oauth2/access_token']), 'prov');
        $this->assertSame('https://sys.test/ent/oauth2/access_token', $relative->tokenUrl('https://sys.test/ent'), 'a token path is under the system\'s own address');
        $this->assertNull($this->standIn('hr')->tokenUrl('https://sys.test/api'), 'a system that does not sign in with a token has none');
    }

    public function test_the_mock_never_echoes_what_it_was_sent(): void
    {
        $res = $this->standIn('hr', 'ok', 'secret-key')->respond('GET', '/employees', [], ['authorization' => 'Bearer wrong-key-123'], '');
        $this->assertStringNotContainsString('wrong-key-123', $res['body']);
        $this->assertStringNotContainsString('secret-key', $res['body']);
    }

    public function test_the_same_fixtures_stand_in_for_the_system_in_tests(): void
    {
        ContractFixtures::fake('hr', 'https://sys.test/api');
        $driver = new ContractDriver(app(ContractStore::class)->active('hr'), ['base_url' => 'https://sys.test/api', 'api_key' => 'KEY-123'], ['api_key'], 'hr');
        $rows = iterator_to_array($driver->each('fetch_employees'), false);
        $this->assertSame(['E-1001', 'E-1002', 'E-0900'], array_column($rows, 'employee_no'));
    }

    public function test_a_test_can_choose_the_scenario_of_one_operation(): void
    {
        ContractFixtures::fake('hr', 'https://sys.test/api', ['fetch_employees' => 'server_error']);
        $driver = new ContractDriver(app(ContractStore::class)->active('hr'), ['base_url' => 'https://sys.test/api', 'api_key' => 'KEY-123'], ['api_key'], 'hr');
        try {
            $driver->call('fetch_employees');
            $this->fail('the scenario was not applied');
        } catch (ContractException $e) {
            $this->assertSame(['upstream_error', true], [$e->errorCode, $e->retryable]);
        }
    }

    public function test_every_operation_of_every_system_has_an_ok_fixture_or_a_documented_reason(): void
    {
        foreach (Contract::systems() as $system) {
            $mock = $this->standIn($system);
            foreach (Contract::bundled($system)->operations() as $name => $op) {
                $this->assertFileExists(resource_path("contracts/{$system}/fixtures/{$name}.ok.json"), "{$system}.{$name}");
            }
            $this->assertNotSame([], $mock->routes(), $system);
        }
    }
}
