<?php

namespace Tests\Feature;

use App\Integrations\Contracts\Contract;
use App\Integrations\Contracts\ContractDriver;
use App\Integrations\Contracts\ContractException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The generic HTTP driver: it executes an operation of a contract — URL, query, headers and body from templates, the contract's
 * authentication, pagination, a response map, an error model — against the system's own address and nobody else's.
 */
class ContractDriverTest extends TestCase
{
    private const SECRETS = ['api_key', 'password', 'client_secret', 'hmac_secret', 'client_key'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @param  array<string, mixed>  $patch */
    private function driver(array $ops = [], array $auth = ['type' => 'bearer', 'setting' => 'api_key'], array $settings = []): ContractDriver
    {
        $contract = new Contract('hr', ['system' => 'hr', 'auth' => $auth, 'operations' => $ops ?: [
            'list' => ['method' => 'GET', 'path' => '/employees', 'query' => ['since?' => '{{ since }}', 'lang' => 'ar'], 'headers' => ['Accept-Language' => 'ar'], 'response' => ['records' => '$.data']],
        ]]);

        return new ContractDriver($contract, $settings + ['base_url' => 'https://sys.test/api', 'api_key' => 'KEY-123'], self::SECRETS, 'hr');
    }

    public function test_it_builds_the_request_from_the_templates_and_leaves_out_what_is_empty(): void
    {
        Http::fake(['sys.test/*' => Http::response(['data' => [['no' => 'E-1']]])]);
        $this->driver()->call('list', ['since' => '2026-10-01']);
        $this->driver()->call('list');

        $sent = Http::recorded();
        $this->assertSame('https://sys.test/api/employees?since=2026-10-01&lang=ar', (string) $sent[0][0]->url());
        $this->assertSame('https://sys.test/api/employees?lang=ar', (string) $sent[1][0]->url(), 'an empty optional value is not sent');
        $this->assertSame(['ar'], $sent[0][0]->header('Accept-Language'));
        $this->assertSame('GET', $sent[0][0]->method());
    }

    public function test_the_system_s_address_is_the_only_address_it_can_reach(): void
    {
        Http::fake();
        $d = $this->driver(['x' => ['method' => 'GET', 'path' => '/employees']]);
        $this->assertSame('https://sys.test/api/employees', $d->request('x')->url);
        // Even a template cannot turn the path into another host: a value is escaped into the path and cannot contain a slash or a host.
        $d = $this->driver(['x' => ['method' => 'GET', 'path' => '/employees/{{ id }}']]);
        $this->assertSame('https://sys.test/api/employees/..%2F..%2F%40evil.example', $d->request('x', ['id' => '../../@evil.example'])->url);
        Http::assertNothingSent();
    }

    public function test_a_path_that_a_setting_or_a_value_would_turn_into_another_address_is_refused(): void
    {
        Http::fake();
        foreach (['@evil.example/x', '//evil.example/x', '/ok/../../x', '/x?y=1', '/x#f', "/x\r\nHost: evil", 'https://evil.example', 'employees'] as $bad) {
            $d = $this->driver(['x' => ['method' => 'GET', 'path' => '{{ settings.employees_path|raw }}']], settings: ['base_url' => 'https://sys.test', 'employees_path' => $bad]);
            try {
                $d->request('x');
                $this->fail('"'.addcslashes($bad, "\r\n").'" was accepted as a path');
            } catch (ContractException $e) {
                $this->assertSame('bad_path', $e->errorCode, $bad);
            }
        }
        try {
            $this->driver(['x' => ['method' => 'GET', 'path' => '/employees/{{ id }}']])->request('x', ['id' => '..']);
            $this->fail('a ".." segment was accepted');
        } catch (ContractException $e) {
            $this->assertSame('bad_path', $e->errorCode);
        }
        $this->assertSame('https://sys.test/api/v2/staff', $this->driver(['x' => ['method' => 'GET', 'path' => '{{ settings.employees_path|raw }}']], settings: ['employees_path' => '/v2/staff'])->request('x')->url);
        Http::assertNothingSent();
    }

    public function test_the_header_an_api_key_travels_in_is_a_plain_name_and_must_be_set(): void
    {
        $auth = ['type' => 'api_key', 'in' => 'header', 'name' => 'X-Api-Key', 'name_setting' => 'auth_header', 'setting' => 'api_key'];
        $this->assertTrue(array_key_exists('X-Api-Key', $this->driver(auth: $auth)->request('list')->headers), 'the contract\'s own name is the fallback');
        $this->assertTrue(array_key_exists('X-Site-Key', $this->driver(auth: $auth, settings: ['auth_header' => 'X-Site-Key'])->request('list')->headers));
        foreach (["X-Evil\r\nInjected: 1", 'Bad Name', '-x'] as $bad) {
            try {
                $this->driver(auth: $auth, settings: ['auth_header' => $bad])->request('list');
                $this->fail('"'.addcslashes($bad, "\r\n").'" was accepted as a header name');
            } catch (ContractException $e) {
                $this->assertSame('not_configured', $e->errorCode);
            }
        }
        $this->expectException(ContractException::class);
        $this->driver(auth: ['type' => 'api_key', 'name_setting' => 'auth_header', 'setting' => 'api_key'])->request('list');
    }

    public function test_an_optional_credential_that_is_not_set_is_left_out_and_a_required_one_is_an_error(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $optional = ['type' => 'bearer', 'setting' => 'api_key', 'optional' => true];
        $this->driver(auth: $optional, settings: ['api_key' => ''])->call('list');
        $this->driver(auth: ['type' => 'api_key', 'name' => 'X-Api-Key', 'setting' => 'api_key', 'optional' => true], settings: ['api_key' => ''])->call('list');
        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization') && ! $r->hasHeader('X-Api-Key'));
        $this->driver(auth: $optional)->call('list');
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer KEY-123'));

        $this->expectException(ContractException::class);
        $this->driver(settings: ['api_key' => ''])->call('list');
    }

    public function test_bearer_api_key_and_basic_auth(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $this->driver()->call('list');
        $this->driver(auth: ['type' => 'api_key', 'in' => 'header', 'name' => 'X-Api-Key', 'setting' => 'api_key'])->call('list');
        $this->driver(auth: ['type' => 'api_key', 'in' => 'query', 'name' => 'key', 'setting' => 'api_key'])->call('list');
        $this->driver(auth: ['type' => 'basic', 'user_setting' => 'username', 'password_setting' => 'password'], settings: ['username' => 'tedc', 'password' => 'p@ss'])->call('list');
        $this->driver(auth: ['type' => 'none'])->call('list');

        $sent = Http::recorded();
        $this->assertTrue($sent[0][0]->hasHeader('Authorization', 'Bearer KEY-123'));
        $this->assertTrue($sent[1][0]->hasHeader('X-Api-Key', 'KEY-123'));
        $this->assertStringContainsString('key=KEY-123', (string) $sent[2][0]->url());
        $this->assertTrue($sent[3][0]->hasHeader('Authorization', 'Basic '.base64_encode('tedc:p@ss')));
        $this->assertFalse($sent[4][0]->hasHeader('Authorization'));
    }

    public function test_a_missing_credential_is_said_plainly_before_anything_is_sent(): void
    {
        Http::fake();
        try {
            $this->driver(settings: ['api_key' => ''])->call('list');
            $this->fail('expected an exception');
        } catch (ContractException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }
        try {
            $this->driver(settings: ['base_url' => ''])->call('list');
            $this->fail('expected an exception');
        } catch (ContractException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_the_signature_covers_the_exact_body_and_the_time(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        Http::fake(['*' => Http::response(['ok' => true])]);
        $d = $this->driver(['pay' => ['method' => 'POST', 'path' => '/payments', 'body' => ['format' => 'json', 'template' => ['order' => '{{ order }}', 'amount' => '{{ amount|amount:3 }}', 'note' => '{{ note }}']]]],
            ['type' => 'hmac', 'secret_setting' => 'hmac_secret', 'algorithm' => 'hmac-sha256', 'signature_header' => 'X-Signature', 'timestamp_header' => 'X-Timestamp'], ['hmac_secret' => 'shh']);
        $d->call('pay', ['order' => 'ORD-1', 'amount' => 12.5, 'note' => 'سداد "الدورة"']);

        /** @var Request $r */
        $r = Http::recorded()[0][0];
        $body = '{"order":"ORD-1","amount":"12.500","note":"سداد \"الدورة\""}';
        $this->assertSame($body, $r->body(), 'Arabic is sent as UTF-8, not escaped away');
        $ts = (string) Carbon::now()->timestamp;
        $this->assertSame([$ts], $r->header('X-Timestamp'));
        $this->assertSame([hash_hmac('sha256', $ts.'.'.$body, 'shh')], $r->header('X-Signature'));
        $this->assertTrue($r->hasHeader('Content-Type', 'application/json'));
        Carbon::setTestNow();
    }

    public function test_oauth2_fetches_a_token_once_and_reuses_it(): void
    {
        Http::fake(['auth.test/*' => Http::response(['access_token' => 'TOK-1', 'expires_in' => 3600]), 'sys.test/*' => Http::response(['data' => []])]);
        $auth = ['type' => 'oauth2', 'token_url_setting' => 'token_url', 'client_id_setting' => 'client_id', 'client_secret_setting' => 'client_secret', 'scope' => 'read'];
        $settings = ['token_url' => 'https://auth.test/token', 'client_id' => 'tedc', 'client_secret' => 'sec'];
        $this->driver(auth: $auth, settings: $settings)->call('list');
        $this->driver(auth: $auth, settings: $settings)->call('list');

        $reqs = collect(Http::recorded())->map(fn ($p) => $p[0]);
        $this->assertSame(1, $reqs->filter(fn ($r) => str_contains($r->url(), 'auth.test'))->count(), 'one token request');
        $tokenRequest = $reqs->first(fn ($r) => str_contains($r->url(), 'auth.test'));
        $this->assertSame(['client_credentials', 'tedc', 'sec', 'read'], [$tokenRequest['grant_type'], $tokenRequest['client_id'], $tokenRequest['client_secret'], $tokenRequest['scope']]);
        $this->assertTrue($reqs->last()->hasHeader('Authorization', 'Bearer TOK-1'));
    }

    public function test_a_client_certificate_is_offered_for_mutual_tls_and_not_left_on_disk(): void
    {
        $d = $this->driver(auth: ['type' => 'mtls', 'cert_setting' => 'client_cert', 'key_setting' => 'client_key'], settings: ['client_cert' => "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----", 'client_key' => "-----BEGIN PRIVATE KEY-----\nBBB\n-----END PRIVATE KEY-----"]);
        $options = $d->clientOptions();
        $this->assertFileExists($options['cert']);
        $this->assertFileExists($options['ssl_key']);
        $this->assertSame(0600, fileperms($options['ssl_key']) & 0777);
        $this->assertStringContainsString('BBB', (string) file_get_contents($options['ssl_key']));
        $d->cleanup();
        $this->assertFileDoesNotExist($options['ssl_key']);
        $this->assertFileDoesNotExist($options['cert']);
    }

    /** @param  array<string, mixed>  $pagination  @param  array<string, mixed>  $response */
    private function pagedOperation(array $pagination, array $response = []): array
    {
        return ['list' => ['method' => 'GET', 'path' => '/employees', 'pagination' => $pagination, 'response' => ['records' => '$.data'] + $response]];
    }

    /** @return list<string> */
    private function urls(): array
    {
        return collect(Http::recorded())->map(fn ($p) => (string) $p[0]->url())->all();
    }

    public function test_pages_are_followed_by_page_number(): void
    {
        Http::fake(['sys.test/*' => Http::sequence()->push(['data' => [['n' => 1], ['n' => 2]], 'meta' => ['next_page' => 2]])->push(['data' => [['n' => 3]], 'meta' => ['next_page' => null]])]);
        $rows = iterator_to_array($this->driver($this->pagedOperation(['type' => 'page', 'param' => 'page', 'start' => 1, 'size_param' => 'per_page', 'size' => 2], ['next_page' => '$.meta.next_page']))->each('list'), false);
        $this->assertSame([1, 2, 3], array_column($rows, 'n'));
        $this->assertSame(['https://sys.test/api/employees?page=1&per_page=2', 'https://sys.test/api/employees?page=2&per_page=2'], $this->urls());
    }

    public function test_pages_are_followed_by_offset_until_the_total_is_reached(): void
    {
        Http::fake(['sys.test/*' => Http::sequence()->push(['data' => [['n' => 1], ['n' => 2]], 'total' => 3])->push(['data' => [['n' => 3]], 'total' => 3])]);
        $rows = iterator_to_array($this->driver($this->pagedOperation(['type' => 'offset', 'param' => 'offset', 'size_param' => 'limit', 'size' => 2], ['total' => '$.total']))->each('list'), false);
        $this->assertSame([1, 2, 3], array_column($rows, 'n'));
        $this->assertSame(['https://sys.test/api/employees?offset=0&limit=2', 'https://sys.test/api/employees?offset=2&limit=2'], $this->urls());
    }

    public function test_pages_are_followed_by_cursor_until_there_is_none(): void
    {
        Http::fake(['sys.test/*' => Http::sequence()->push(['data' => [['n' => 1]], 'cursor' => 'abc'])->push(['data' => [['n' => 2]], 'cursor' => null])]);
        $rows = iterator_to_array($this->driver($this->pagedOperation(['type' => 'cursor', 'param' => 'after'], ['next_cursor' => '$.cursor']))->each('list'), false);
        $this->assertSame([1, 2], array_column($rows, 'n'));
        $this->assertSame(['https://sys.test/api/employees', 'https://sys.test/api/employees?after=abc'], $this->urls());
    }

    public function test_paging_never_runs_away(): void
    {
        Http::fake(['sys.test/*' => Http::response(['data' => [['n' => 1]], 'meta' => ['next_page' => 99]])]);
        $d = $this->driver(['list' => ['method' => 'GET', 'path' => '/e', 'pagination' => ['type' => 'page', 'param' => 'page', 'max_pages' => 3], 'response' => ['records' => '$.data', 'next_page' => '$.meta.next_page']]]);
        $this->assertCount(3, iterator_to_array($d->each('list'), false), 'stops at max_pages');
    }

    public function test_errors_map_to_stable_codes_and_never_echo_secrets(): void
    {
        $ops = ['list' => ['method' => 'GET', 'path' => '/employees', 'errors' => ['404' => ['code' => 'no_such_employee', 'message' => ['en' => 'No such employee', 'ar' => 'لا يوجد موظف']]], 'response' => ['error' => '$.error.message']]];
        $cases = [
            [401, [], 'unauthorised', false], [403, [], 'unauthorised', false], [404, [], 'no_such_employee', false], [422, ['error' => ['message' => 'bad field']], 'rejected', false],
            [429, [], 'rate_limited', true], [500, [], 'upstream_error', true], [503, [], 'upstream_error', true],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$status, $body]) {
            $sequence->push($body, $status);
        }
        Http::fake(['sys.test/*' => $sequence]);
        foreach ($cases as [$status, , $code, $retryable]) {
            try {
                $this->driver($ops)->call('list');
                $this->fail("expected an error for {$status}");
            } catch (ContractException $e) {
                $this->assertSame([$code, $retryable, $status], [$e->errorCode, $e->retryable, $e->status], (string) $status);
                $this->assertStringNotContainsString('KEY-123', $e->getMessage());
            }
        }
    }

    public function test_the_systems_own_words_about_a_rejection_are_kept_shortened_and_without_markup(): void
    {
        Http::fake(['sys.test/*' => Http::response(['error' => ['message' => '<b>Bad</b> field: '.str_repeat('x', 400)]], 422)]);
        try {
            $this->driver(['list' => ['method' => 'GET', 'path' => '/employees', 'response' => ['error' => '$.error.message']]])->call('list');
            $this->fail('expected an error');
        } catch (ContractException $e) {
            $this->assertStringContainsString('Bad field', $e->getMessage());
            $this->assertStringNotContainsString('<b>', $e->getMessage());
            $this->assertLessThan(260, mb_strlen($e->getMessage()));
        }
    }

    public function test_a_system_that_cannot_be_reached_is_a_retryable_error_that_does_not_leak_the_key(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: sys.test (see https://curl.se) for https://sys.test/api/employees?key=KEY-123'));
        try {
            $this->driver(auth: ['type' => 'api_key', 'in' => 'query', 'name' => 'key', 'setting' => 'api_key'])->call('list');
            $this->fail('expected an exception');
        } catch (ContractException $e) {
            $this->assertSame(['unreachable', true], [$e->errorCode, $e->retryable]);
            $this->assertStringNotContainsString('KEY-123', $e->getMessage(), 'the key is scrubbed from the message');
            $this->assertStringContainsString('•••', $e->getMessage());
        }
    }

    public function test_a_request_preview_shows_what_would_be_sent_with_credentials_masked(): void
    {
        Http::fake();
        $d = $this->driver(['x' => ['method' => 'POST', 'path' => '/items', 'body' => ['format' => 'json', 'template' => ['n' => '{{ n }}']]]]);
        $preview = $d->request('x', ['n' => 5])->masked();
        $this->assertSame(['POST', 'https://sys.test/api/items', '{"n":5}'], [$preview['method'], $preview['url'], $preview['body']]);
        $this->assertSame('•••', $preview['headers']['Authorization']);
        $q = $this->driver(auth: ['type' => 'api_key', 'in' => 'query', 'name' => 'key', 'setting' => 'api_key'])->request('list')->masked();
        $this->assertStringContainsString('key=%E2%80%A2%E2%80%A2%E2%80%A2', $q['url'], 'a key in the address is masked too');
        $this->assertStringNotContainsString('KEY-123', json_encode($q));
        Http::assertNothingSent();
    }

    public function test_a_retry_carries_the_same_idempotency_key(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $d = $this->driver(['push' => ['method' => 'POST', 'path' => '/items', 'body' => ['format' => 'json', 'template' => ['n' => '{{ n }}']], 'idempotency' => ['header' => 'Idempotency-Key']]]);
        $d->call('push', ['n' => 1], ['idempotency_key' => 'ticket-77']);
        $d->call('push', ['n' => 1], ['idempotency_key' => 'ticket-77']);
        $d->call('push', ['n' => 1]);
        $keys = collect(Http::recorded())->map(fn ($p) => $p[0]->header('Idempotency-Key')[0] ?? null)->all();
        $this->assertSame(['ticket-77', 'ticket-77'], array_slice($keys, 0, 2));
        $this->assertNotEmpty($keys[2], 'a key is made when none is given');
    }

    public function test_the_rate_limit_is_kept(): void
    {
        RateLimiter::clear('contract:hr:list');
        Http::fake(['*' => Http::response(['data' => []])]);
        $d = $this->driver(['list' => ['method' => 'GET', 'path' => '/e', 'rate_limit' => ['per_minute' => 2]]]);
        $d->call('list');
        $d->call('list');
        $this->expectException(ContractException::class);
        $this->expectExceptionMessageMatches('/too many calls/i');
        $d->call('list');
    }

    public function test_an_insecure_address_is_refused_in_production(): void
    {
        Http::fake();
        $this->app['env'] = 'production';
        $this->expectException(ContractException::class);
        $this->expectExceptionMessageMatches('/https/i');
        $this->driver(settings: ['base_url' => 'http://sys.test'])->call('list');
    }

    public function test_responses_read_through_the_map_and_are_checked_against_the_schema(): void
    {
        $op = ['list' => ['method' => 'GET', 'path' => '/e', 'response' => ['records' => '$.data', 'values' => ['ticket' => '$.ticket.no'], 'total' => '$.meta.total'],
            'response_schema' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['no'], 'properties' => ['no' => ['type' => 'string']]]]]]]];
        Http::fake(['sys.test/*' => Http::sequence()->push(['data' => [['no' => 'E-1']], 'ticket' => ['no' => 'T-9'], 'meta' => ['total' => 41]])->push(['data' => [['no' => 7]]])->push(['rows' => []])]);
        $r = $this->driver($op)->call('list');
        $this->assertSame([['no' => 'E-1']], $r->records());
        $this->assertSame(['T-9', 41, []], [$r->value('ticket'), $r->marker('total'), $r->schemaProblems()]);
        $this->assertNotSame([], $this->driver($op)->call('list')->schemaProblems(), 'a number where the contract says a string');
        $this->assertNotSame([], $this->driver($op)->call('list')->schemaProblems(), 'a missing required field');
    }

    public function test_a_path_from_a_setting_a_header_named_by_a_setting_and_fallback_value_paths(): void
    {
        Http::fake(['sys.test/*' => Http::response(['id' => 'ID-5'])]);
        $d = $this->driver(['create' => ['method' => 'POST', 'path' => '{{ settings.create_path|default:/tickets|raw }}', 'body' => ['format' => 'json', 'template' => ['n' => '{{ n }}']], 'response' => ['values' => ['ticket' => ['$.ticket_no', '$.id']]]]],
            ['type' => 'api_key', 'in' => 'header', 'name_setting' => 'auth_header', 'setting' => 'api_key'], ['auth_header' => 'X-Ministry-Key', 'create_path' => '/v2/tickets']);
        $r = $d->call('create', ['n' => 1]);
        $sent = Http::recorded()[0][0];
        $this->assertSame('https://sys.test/api/v2/tickets', (string) $sent->url());
        $this->assertTrue($sent->hasHeader('X-Ministry-Key', 'KEY-123'));
        $this->assertSame('ID-5', $r->value('ticket'), 'the first path that has a value wins');
        $this->assertSame('https://sys.test/api/tickets', $this->driver(['create' => ['method' => 'POST', 'path' => '{{ settings.create_path|default:/tickets|raw }}']])->request('create')->url, 'the default applies');
    }

    public function test_a_form_body_and_a_raw_body(): void
    {
        Http::fake(['*' => Http::response([])]);
        $d = $this->driver([
            'form' => ['method' => 'POST', 'path' => '/f', 'body' => ['format' => 'form', 'template' => ['to' => '{{ to }}', 'text' => '{{ text }}']]],
            'raw' => ['method' => 'POST', 'path' => '/r', 'headers' => ['Content-Type' => 'application/xml'], 'body' => ['format' => 'raw', 'template' => '<m to="{{ to }}">{{ text }}</m>']],
        ]);
        $d->call('form', ['to' => '97455512345', 'text' => 'مرحبا & hello']);
        $d->call('raw', ['to' => '974', 'text' => 'x']);
        $sent = Http::recorded();
        $this->assertSame('to=97455512345&text=%D9%85%D8%B1%D8%AD%D8%A8%D8%A7+%26+hello', $sent[0][0]->body());
        $this->assertTrue($sent[0][0]->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
        $this->assertSame('<m to="974">x</m>', $sent[1][0]->body());
    }
}
