<?php

namespace App\Payments\Gateways;

use App\Integrations\Contracts\ContractDriver;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\Signature;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * The Ministry's e-payment gateway (hosted payment page with redirect and a signed server-to-server callback), described by its contract
 * (Settings → Integrations → Ministry e-payment gateway → Contract): the request fields, the amount format, the language words, the
 * signature (HMAC-SHA256/512, or RSA-SHA256 with the keys), the callback's header and how its status words become captured, failed or
 * cancelled. The shipped contract is an ASSUMPTION until the Ministry supplies its specification: JSON over HTTPS, a merchant id, HMAC-SHA256
 * over the raw body (hex), statuses SUCCESS / FAILED / CANCELLED. A real specification that differs is a contract edit, not a deploy.
 */
class MoeEpayGateway implements PaymentGateway
{
    /** @param  array<string, mixed>  $s  settings from Settings → Integrations (base_url, merchant_id, secret, private_key, callback_key) */
    public function __construct(private readonly array $s) {}

    public function key(): string
    {
        return 'moe_epay';
    }

    private function store(): ContractStore
    {
        return app(ContractStore::class);
    }

    private function driver(): ContractDriver
    {
        return new ContractDriver($this->store()->active('moe_epay'), $this->s, $this->store()->secretSettings('moe_epay'), 'moe_epay');
    }

    public function initiate(Order $order, string $returnUrl, string $callbackUrl): array
    {
        $answer = $this->driver()->call('initiate', [
            'order_number' => $order->number, 'amount' => (float) $order->total, 'currency' => $order->currency, 'language' => app()->getLocale() === 'en' ? 'en' : 'ar',
            'return_url' => $returnUrl, 'callback_url' => $callbackUrl, 'description' => 'TEDC '.$order->number,
        ], ['idempotency_key' => 'tedc-order-'.$order->number]);   // a retry of the same order must not start a second payment

        return ['ref' => (string) $answer->value('payment_id'), 'redirect_url' => (string) $answer->value('redirect_url')];
    }

    public function verify(string $rawBody, ?string $signature, array $headers = []): bool
    {
        $spec = (array) ($this->store()->active('moe_epay')->inbound()['signature'] ?? []) ?: ['header' => 'X-Signature', 'algorithm' => 'hmac-sha256', 'encoding' => 'hex', 'payload' => '{{ body }}', 'secret_setting' => 'secret'];
        $given = $headers !== [] ? ($headers[strtolower((string) ($spec['header'] ?? 'X-Signature'))] ?? null) : $signature;
        $stamped = ! empty($spec['timestamp_header']);

        return Signature::verify($spec, (string) ($this->s[$spec['secret_setting'] ?? 'secret'] ?? ''), $given, $stamped ? (string) ($headers[strtolower((string) $spec['timestamp_header'])] ?? '') : '', $rawBody, $stamped ? (int) ($spec['tolerance'] ?? 300) : null);
    }

    public function parse(array $payload): array
    {
        $m = $this->store()->active('moe_epay')->map('callback', $payload);
        $status = in_array($m['status'] ?? '', ['captured', 'cancelled', 'failed'], true) ? $m['status'] : 'failed';   // a word the table does not know is never a payment

        return ['event_id' => (string) ($m['event_id'] ?? ''), 'ref' => (string) ($m['ref'] ?? ''), 'status' => $status, 'amount' => (float) ($m['amount'] ?? 0), 'order_number' => isset($m['order_number']) ? (string) $m['order_number'] : null];
    }

    public function refund(Payment $payment, float $amount, string $reason, ?string $idempotencyKey = null): array
    {
        $answer = $this->driver()->call('refund', ['payment_id' => (string) $payment->gateway_ref, 'amount' => $amount, 'reason' => $reason], array_filter(['idempotency_key' => $idempotencyKey]));

        return ['ref' => (string) $answer->value('refund_id')];
    }

    public function statement(Carbon $day): array
    {
        $driver = $this->driver();
        $rows = [];
        foreach ($driver->each('statement', ['date' => $day->toDateString()]) as $record) {
            $m = $driver->contract()->map('transaction', $record);
            $rows[] = ['ref' => (string) ($m['ref'] ?? ''), 'status' => strtolower((string) ($m['status'] ?? '')), 'amount' => (float) ($m['amount'] ?? 0)];
        }

        return $rows;
    }
}
