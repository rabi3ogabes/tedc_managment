<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\InvalidContract;
use App\Integrations\IntegrationManager;
use App\Integrations\WebhookSignature;
use App\Models\Employee;
use App\Models\Role;
use Tests\TestCase;

/**
 * How a Ministry system signs what it sends us is part of its contract: the header, the algorithm (HMAC-SHA256/512, or RSA-SHA256 with its
 * public key), the encoding, what is signed and how old a message may be. The shipped scheme is TEDC's own; a different real one is a
 * contract edit, not a deploy. A repeated message is acknowledged, never applied twice.
 */
class InboundWebhookContractTest extends TestCase
{
    private const SECRET = 'hr-inbound-secret';

    protected function setUp(): void
    {
        parent::setUp();
        app(IntegrationManager::class)->update('hr', ['driver' => 'fake', 'enabled' => true, 'settings' => ['inbound_secret' => self::SECRET]]);
        $this->makeEmployee(['employee_no' => 'E-1', 'qualification' => 'bachelor'], $this->makeUser(Role::EMPLOYEE, ['email' => 'e1@moe.gov.qa']));
    }

    private function inbound(array $spec, ?array $idempotency = null): void
    {
        $store = app(ContractStore::class);
        $doc = $store->active('hr')->document();
        $doc['inbound'] = ['signature' => $spec] + ($idempotency ? ['idempotency' => $idempotency] : []);
        $store->save('hr', 'contract', $doc, null);
    }

    private function deliver(array $body, array $headers)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/v1/integrations/hr/inbound', [], [], [], $server, json_encode($body));
    }

    private function change(string $id = 'm1', string $qualification = 'master'): array
    {
        return ['id' => $id, 'type' => 'employee.updated', 'employee' => ['employee_no' => 'E-1', 'qualification' => $qualification]];
    }

    public function test_the_shipped_scheme_is_tedcs_own_with_or_without_the_prefix(): void
    {
        $this->assertSame('X-TEDC-Signature', app(ContractStore::class)->active('hr')->inbound()['signature']['header']);
        $body = $this->change();
        $ts = (string) time();
        $sig = WebhookSignature::sign(self::SECRET, $ts, json_encode($body));

        $this->deliver($body, ['X-TEDC-Timestamp' => $ts, 'X-TEDC-Signature' => $sig])->assertOk()->assertJsonPath('data.status', 'processed');
        $this->deliver($this->change('m2', 'phd'), ['X-TEDC-Timestamp' => $ts, 'X-TEDC-Signature' => 'sha256='.WebhookSignature::sign(self::SECRET, $ts, json_encode($this->change('m2', 'phd')))])->assertOk()->assertJsonPath('data.status', 'processed');
        $this->assertSame('phd', Employee::where('employee_no', 'E-1')->value('qualification'));
    }

    public function test_a_different_header_algorithm_and_encoding_are_a_contract_edit(): void
    {
        $this->inbound(['header' => 'X-Hr-Signature', 'algorithm' => 'hmac-sha512', 'encoding' => 'base64', 'payload' => '{{ body }}', 'secret_setting' => 'inbound_secret']);
        $body = $this->change();
        $raw = json_encode($body);
        $good = base64_encode(hash_hmac('sha512', $raw, self::SECRET, true));

        $this->deliver($body, ['X-Hr-Signature' => $good])->assertOk()->assertJsonPath('data.status', 'processed');
        $this->deliver($this->change('m2'), ['X-Hr-Signature' => 'AAAA'])->assertUnauthorized();
        $this->deliver($this->change('m3'), ['X-Hr-Signature' => base64_encode(hash_hmac('sha512', json_encode($this->change('m3')), 'wrong', true))])->assertUnauthorized();
        $ts = (string) time();
        $this->deliver($this->change('m4'), ['X-TEDC-Timestamp' => $ts, 'X-TEDC-Signature' => WebhookSignature::sign(self::SECRET, $ts, json_encode($this->change('m4')))])->assertUnauthorized();   // the old scheme no longer applies
        $this->deliver($this->change('m5'), [])->assertUnauthorized();
    }

    public function test_a_timestamp_header_with_its_own_tolerance_stops_a_replay(): void
    {
        $this->inbound(['header' => 'X-Signature', 'timestamp_header' => 'X-Timestamp', 'algorithm' => 'hmac-sha256', 'encoding' => 'hex', 'payload' => '{{ timestamp }}:{{ body }}', 'tolerance' => 60]);
        $send = function (string $id, int $age) {
            $body = $this->change($id);
            $ts = (string) (time() - $age);

            return $this->deliver($body, ['X-Timestamp' => $ts, 'X-Signature' => hash_hmac('sha256', $ts.':'.json_encode($body), self::SECRET)]);
        };

        $send('r1', 10)->assertOk()->assertJsonPath('data.status', 'processed');
        $send('r2', 120)->assertUnauthorized();
    }

    public function test_a_system_that_signs_with_a_private_key_is_checked_with_its_public_key(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        app(IntegrationManager::class)->update('hr', ['settings' => ['inbound_secret' => $public]]);
        $this->inbound(['header' => 'X-Signature', 'algorithm' => 'rsa-sha256', 'encoding' => 'base64', 'payload' => '{{ body }}']);

        $body = $this->change();
        openssl_sign(json_encode($body), $signature, $private, OPENSSL_ALGO_SHA256);
        $this->deliver($body, ['X-Signature' => base64_encode($signature)])->assertOk()->assertJsonPath('data.status', 'processed');

        $other = $this->change('m2', 'phd');
        $this->deliver($other, ['X-Signature' => base64_encode($signature)])->assertUnauthorized();   // a signature of another message
    }

    public function test_the_idempotency_key_comes_from_where_the_contract_says(): void
    {
        $this->inbound(['header' => 'X-Signature', 'algorithm' => 'hmac-sha256', 'encoding' => 'hex', 'payload' => '{{ body }}'], ['header' => 'X-Event-Id', 'path' => '$.event.uid']);
        $send = function (array $body, ?string $header = null) {
            $headers = ['X-Signature' => hash_hmac('sha256', json_encode($body), self::SECRET)];

            return $this->deliver($body, $headers + ($header ? ['X-Event-Id' => $header] : []));
        };

        $send(['type' => 'employee.updated', 'event' => ['uid' => 'ev-1'], 'employee' => ['employee_no' => 'E-1', 'qualification' => 'master']])->assertOk()->assertJsonPath('data.status', 'processed');
        Employee::where('employee_no', 'E-1')->update(['qualification' => 'diploma']);
        $send(['type' => 'employee.updated', 'event' => ['uid' => 'ev-1'], 'employee' => ['employee_no' => 'E-1', 'qualification' => 'master']])->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertSame('diploma', Employee::where('employee_no', 'E-1')->value('qualification'), 'the repeat changed nothing');
        $send(['type' => 'employee.updated', 'employee' => ['employee_no' => 'E-1', 'qualification' => 'master']], 'ev-2')->assertOk()->assertJsonPath('data.status', 'processed');   // by header
        $send(['type' => 'employee.updated', 'employee' => ['employee_no' => 'E-1', 'qualification' => 'phd']])->assertStatus(422);   // no key at all
    }

    public function test_an_inbound_block_that_could_never_work_is_refused_when_it_is_saved(): void
    {
        $store = app(ContractStore::class);
        $doc = $store->active('hr')->document();
        foreach ([
            ['header' => "X-Sig\r\nInjected", 'algorithm' => 'hmac-sha256'],
            ['header' => 'X-Signature', 'algorithm' => 'md5'],
            ['header' => 'X-Signature', 'algorithm' => 'hmac-sha256', 'encoding' => 'rot13'],
            ['header' => 'X-Signature', 'algorithm' => 'hmac-sha256', 'payload' => '{{ settings.inbound_secret }}'],
            ['header' => 'X-Signature', 'algorithm' => 'hmac-sha256', 'timestamp_header' => 'X-Ts', 'tolerance' => -5],
            ['algorithm' => 'hmac-sha256'],
        ] as $spec) {
            $doc['inbound'] = ['signature' => $spec];
            try {
                $store->save('hr', 'contract', $doc, null);
                $this->fail('accepted: '.json_encode($spec));
            } catch (InvalidContract $e) {
                $this->assertNotSame([], $e->problems);
            }
        }
    }
}
