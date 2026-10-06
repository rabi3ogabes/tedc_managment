<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The Ministry's e-payment gateway (hosted payment page with redirect and a signed server-to-server callback).
 * The wire format below is an ASSUMPTION until the Ministry supplies its specification: JSON over HTTPS, a merchant id and a shared secret,
 * HMAC-SHA256 signatures over the raw body (hex), statuses SUCCESS / FAILED / CANCELLED. Only the field names in this class change when the real one arrives.
 */
class MoeEpayGateway implements PaymentGateway
{
    /** @param  array<string, mixed>  $s  settings from Settings → Integrations (base_url, merchant_id, secret) */
    public function __construct(private readonly array $s) {}

    public function key(): string
    {
        return 'moe_epay';
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)->acceptJson()->withHeaders(['X-Merchant-Id' => (string) ($this->s['merchant_id'] ?? '')]);
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->s['base_url'] ?? ''), '/').$path;
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) ($this->s['secret'] ?? ''));
    }

    public function initiate(Order $order, string $returnUrl, string $callbackUrl): array
    {
        $body = ['merchant_id' => $this->s['merchant_id'] ?? null, 'order_number' => $order->number, 'amount' => number_format((float) $order->total, 2, '.', ''), 'currency' => $order->currency, 'return_url' => $returnUrl, 'callback_url' => $callbackUrl,
            'description' => 'TEDC '.$order->number];
        $raw = json_encode($body);
        $res = $this->http()->withHeaders(['X-Signature' => $this->sign($raw)])->withBody($raw, 'application/json')->post($this->url('/payments'))->throw()->json();

        return ['ref' => (string) ($res['payment_id'] ?? $res['id'] ?? ''), 'redirect_url' => (string) ($res['redirect_url'] ?? $res['payment_url'] ?? '')];
    }

    public function verify(string $rawBody, ?string $signature): bool
    {
        return $signature !== null && ($this->s['secret'] ?? '') !== '' && hash_equals($this->sign($rawBody), $signature);
    }

    public function parse(array $payload): array
    {
        $status = match (strtoupper((string) ($payload['status'] ?? ''))) {
            'SUCCESS', 'CAPTURED', 'PAID' => 'captured', 'CANCELLED', 'CANCELED' => 'cancelled', default => 'failed',
        };

        return ['event_id' => (string) ($payload['event_id'] ?? $payload['transaction_id'] ?? ''), 'ref' => (string) ($payload['payment_id'] ?? ''), 'status' => $status, 'amount' => (float) ($payload['amount'] ?? 0), 'order_number' => $payload['order_number'] ?? null];
    }

    public function refund(Payment $payment, float $amount, string $reason): array
    {
        $body = json_encode(['amount' => number_format($amount, 2, '.', ''), 'reason' => $reason]);
        $res = $this->http()->withHeaders(['X-Signature' => $this->sign($body)])->withBody($body, 'application/json')->post($this->url('/payments/'.rawurlencode((string) $payment->gateway_ref).'/refund'))->throw()->json();

        return ['ref' => (string) ($res['refund_id'] ?? $res['id'] ?? '')];
    }

    public function statement(Carbon $day): array
    {
        $rows = $this->http()->get($this->url('/transactions'), ['date' => $day->toDateString()])->throw()->json('data') ?? [];

        return array_map(fn ($r) => ['ref' => (string) ($r['payment_id'] ?? ''), 'status' => strtolower((string) ($r['status'] ?? '')), 'amount' => (float) ($r['amount'] ?? 0)], $rows);
    }
}
