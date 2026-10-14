<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractStore;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Gateways\MoeEpayGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PAY-02: the Ministry's payment gateway is a contract, not code. The request fields, the amount format (two or three decimals, or fils),
 * the language words, the signature (HMAC-SHA256/512, or RSA-SHA256 with keys), the callback's header and status table are all in the
 * contract, so the Ministry's real specification is a contract edit.
 */
class MoeEpayContractTest extends TestCase
{
    private const BASE = 'https://pay.moe.test/api';

    private const SETTINGS = ['base_url' => self::BASE, 'merchant_id' => 'M-77', 'secret' => 'topsecret'];

    private function order(float $total = 150, string $number = 'ORD-2610-AB12CD'): Order
    {
        return (new Order)->forceFill(['number' => $number, 'total' => $total, 'currency' => 'QAR']);
    }

    private function gateway(array $settings = []): MoeEpayGateway
    {
        return new MoeEpayGateway($settings + self::SETTINGS);
    }

    private function override(callable $change, string $kind = 'contract'): void
    {
        $store = app(ContractStore::class);
        $doc = $kind === 'contract' ? $store->active('moe_epay')->document() : $store->active('moe_epay')->mappingDocument();
        $change($doc);
        $store->save('moe_epay', $kind, $doc, null);
    }

    public function test_a_payment_starts_from_the_contract_signed_with_the_merchant_header_and_a_stable_idempotency_key(): void
    {
        ContractFixtures::fake('moe_epay', self::BASE);

        $r = $this->gateway()->initiate($this->order(), 'https://tedc.example/payments/return?order=ORD-2610-AB12CD', 'https://tedc.example/api/v1/payments/callback/moe_epay');

        $this->assertSame(['ref' => 'P-1001', 'redirect_url' => 'https://pay.moe.example/redirect/P-1001'], $r);
        Http::assertSent(function (Request $q) {
            $body = $q->body();

            return $q->url() === self::BASE.'/payments' && $q->method() === 'POST' && $q->hasHeader('X-Merchant-Id', 'M-77') && $q->hasHeader('X-Signature', hash_hmac('sha256', $body, 'topsecret'))
                && $q->hasHeader('Idempotency-Key', 'tedc-order-ORD-2610-AB12CD')
                && $q->data() === ['merchant_id' => 'M-77', 'order_number' => 'ORD-2610-AB12CD', 'amount' => '150.00', 'currency' => 'QAR', 'language' => 'ar', 'return_url' => 'https://tedc.example/payments/return?order=ORD-2610-AB12CD', 'callback_url' => 'https://tedc.example/api/v1/payments/callback/moe_epay', 'description' => 'TEDC ORD-2610-AB12CD'];
        });
    }

    public function test_the_page_language_follows_the_buyers_language(): void
    {
        ContractFixtures::fake('moe_epay', self::BASE);
        app()->setLocale('en');

        $this->gateway()->initiate($this->order(), 'https://tedc.example/r', 'https://tedc.example/c');

        Http::assertSent(fn (Request $q) => $q['language'] === 'en');
    }

    public function test_the_amount_format_the_language_words_and_the_signature_are_a_contract_edit(): void
    {
        $this->override(function (array &$doc) {
            $doc['operations']['initiate']['body']['template']['amount'] = '{{ amount|amount:3 }}';
            $doc['operations']['initiate']['body']['template']['language'] = '{{ language|map:ar=ARA,en=ENG }}';
            $doc['auth'] = ['type' => 'hmac', 'secret_setting' => 'secret', 'signature_header' => 'X-Moe-Signature', 'algorithm' => 'hmac-sha512', 'encoding' => 'base64', 'payload' => '{{ body }}'];
        });
        ContractFixtures::fake('moe_epay', self::BASE);

        $this->gateway()->initiate($this->order(150.5), 'https://tedc.example/r', 'https://tedc.example/c');

        Http::assertSent(fn (Request $q) => $q['amount'] === '150.500' && $q['language'] === 'ARA' && $q->hasHeader('X-Moe-Signature', base64_encode(hash_hmac('sha512', $q->body(), 'topsecret', true))) && ! $q->hasHeader('X-Signature'));
    }

    public function test_the_amount_can_go_in_fils(): void
    {
        $this->override(function (array &$doc) {
            $doc['operations']['initiate']['body']['template']['amount'] = '{{ amount|minor:2 }}';
            $doc['operations']['refund']['body']['template']['amount'] = '{{ amount|minor:2 }}';
        });
        ContractFixtures::fake('moe_epay', self::BASE);

        $this->gateway()->initiate($this->order(19.99), 'https://tedc.example/r', 'https://tedc.example/c');
        $this->gateway()->refund((new Payment)->forceFill(['gateway_ref' => 'P-1001']), 0.5, 'Withdrawn');

        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/payments') && $q['amount'] === '1999');
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/refund') && $q['amount'] === '50');
    }

    public function test_rsa_signs_what_tedc_sends_and_checks_what_the_gateway_calls_back(): void
    {
        $tedc = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($tedc, $tedcPrivate);
        $tedcPublic = openssl_pkey_get_details($tedc)['key'];
        $moe = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($moe, $moePrivate);
        $moePublic = openssl_pkey_get_details($moe)['key'];
        $this->override(function (array &$doc) {
            $doc['auth'] = ['type' => 'hmac', 'secret_setting' => 'private_key', 'signature_header' => 'X-Signature', 'algorithm' => 'rsa-sha256', 'encoding' => 'base64', 'payload' => '{{ body }}'];
            $doc['inbound']['signature'] = ['header' => 'X-Signature', 'algorithm' => 'rsa-sha256', 'encoding' => 'base64', 'payload' => '{{ body }}', 'secret_setting' => 'callback_key'];
        });
        ContractFixtures::fake('moe_epay', self::BASE);
        $gateway = $this->gateway(['private_key' => $tedcPrivate, 'callback_key' => $moePublic]);

        $gateway->initiate($this->order(), 'https://tedc.example/r', 'https://tedc.example/c');
        Http::assertSent(function (Request $q) use ($tedcPublic) {
            $signature = base64_decode((string) ($q->header('X-Signature')[0] ?? ''), true);

            return $signature !== false && openssl_verify($q->body(), $signature, $tedcPublic, OPENSSL_ALGO_SHA256) === 1;   // the Ministry can check it with TEDC's public key
        });

        $raw = json_encode(['event_id' => 'e1', 'payment_id' => 'P-1001', 'status' => 'SUCCESS', 'amount' => '150.00', 'order_number' => 'ORD-1']);
        openssl_sign($raw, $theirs, $moePrivate, OPENSSL_ALGO_SHA256);
        $this->assertTrue($gateway->verify($raw, base64_encode($theirs)));
        $this->assertFalse($gateway->verify($raw.' ', base64_encode($theirs)), 'a changed body');
        $this->assertFalse($gateway->verify($raw, base64_encode(hash_hmac('sha256', $raw, 'topsecret', true))), 'a shared-secret signature where a key signature is required');
        $this->assertFalse($this->gateway(['callback_key' => ''])->verify($raw, base64_encode($theirs)), 'no key, no trust');
    }

    public function test_callbacks_are_verified_by_the_header_the_contract_names_and_read_through_its_status_table(): void
    {
        $this->override(function (array &$doc) {
            $doc['inbound']['signature']['header'] = 'X-Moe-Signature';
        });
        $gateway = $this->gateway();
        $raw = json_encode(['transaction_id' => 'tx-9', 'payment_id' => 'P-1001', 'status' => 'Paid', 'amount' => '150.000', 'order_number' => 'ORD-1']);
        $sig = hash_hmac('sha256', $raw, 'topsecret');

        $this->assertTrue($gateway->verify($raw, null, ['x-moe-signature' => $sig]));
        $this->assertFalse($gateway->verify($raw, null, ['x-signature' => $sig]), 'the old header no longer counts');
        $this->assertFalse($gateway->verify($raw, null, []));
        $this->assertSame(['event_id' => 'tx-9', 'ref' => 'P-1001', 'status' => 'captured', 'amount' => 150.0, 'order_number' => 'ORD-1'], $gateway->parse(json_decode($raw, true)));
        $this->assertSame('cancelled', $gateway->parse(['status' => 'canceled'])['status']);
        $this->assertSame('failed', $gateway->parse(['status' => 'DECLINED'])['status']);
        $this->assertSame('failed', $gateway->parse(['status' => 'SOMETHING_NEW'])['status'], 'an unknown outcome is never a payment');
        $this->assertSame('failed', $gateway->parse([])['status']);
    }

    public function test_an_amount_in_fils_in_the_callback_is_read_back_as_riyals(): void
    {
        $this->override(function (array &$doc) {
            $doc['entities']['callback']['fields']['amount']['transform'] = ['minor:2'];
        }, 'mapping');

        $this->assertSame(150.0, $this->gateway()->parse(['status' => 'SUCCESS', 'amount' => '15000'])['amount']);
        $this->assertSame(19.99, $this->gateway()->parse(['status' => 'SUCCESS', 'amount' => 1999])['amount']);
    }

    public function test_a_refund_and_the_statement_go_through_the_contract(): void
    {
        ContractFixtures::fake('moe_epay', self::BASE);

        $this->assertSame(['ref' => 'RF-1'], $this->gateway()->refund((new Payment)->forceFill(['gateway_ref' => 'P-1001']), 40.5, 'Withdrawn within the free window', 'tedc-refund-abc'));
        Http::assertSent(fn (Request $q) => $q->url() === self::BASE.'/payments/P-1001/refund' && $q['amount'] === '40.50' && $q['reason'] === 'Withdrawn within the free window' && $q->hasHeader('Idempotency-Key', 'tedc-refund-abc') && $q->hasHeader('X-Signature', hash_hmac('sha256', $q->body(), 'topsecret')));

        $this->assertSame([['ref' => 'P-1001', 'status' => 'captured', 'amount' => 150.0], ['ref' => 'P-1002', 'status' => 'failed', 'amount' => 25.0]], $this->gateway()->statement(Carbon::parse('2026-10-13')));
        Http::assertSent(fn (Request $q) => str_starts_with($q->url(), self::BASE.'/transactions?date=2026-10-13') && $q->method() === 'GET' && $q->hasHeader('X-Merchant-Id', 'M-77'));
    }

    public function test_a_refusal_a_rejected_refund_and_a_failure_are_clear_errors_without_the_secret(): void
    {
        ContractFixtures::fake('moe_epay', self::BASE, ['initiate' => 'unauthorised', 'refund' => 'rejected', 'statement' => 'unauthorised']);

        foreach ([fn () => $this->gateway()->initiate($this->order(), 'https://tedc.example/r', 'https://tedc.example/c'), fn () => $this->gateway()->refund((new Payment)->forceFill(['gateway_ref' => 'P-1']), 999, 'x'), fn () => $this->gateway()->statement(Carbon::today())] as $i => $call) {
            try {
                $call();
                $this->fail("call {$i} did not fail");
            } catch (ContractException $e) {
                $this->assertContains($e->errorCode, ['unauthorised', 'rejected']);
                $this->assertStringNotContainsString('topsecret', $e->getMessage());
            }
        }
    }
}
