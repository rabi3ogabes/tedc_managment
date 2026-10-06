<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Training and development gateway: the "hosted page" is a screen of the web app where the person chooses to pay or fail. Same signed-callback contract as the real one. */
class FakeGateway implements PaymentGateway
{
    /** @var list<array{ref: string, status: string, amount: float}> what the pretend gateway lists in its statement (tests fill this) */
    public static array $statement = [];

    public function __construct(private readonly string $secret) {}

    public function key(): string
    {
        return 'fake';
    }

    public function initiate(Order $order, string $returnUrl, string $callbackUrl): array
    {
        $ref = 'FAKE-'.strtoupper(Str::random(12));

        return ['ref' => $ref, 'redirect_url' => rtrim((string) config('tedc.web_url'), '/').'/payments/fake/'.$ref.'?order='.$order->number.'&amount='.$order->total.'&return='.urlencode($returnUrl)];
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->secret);
    }

    public function verify(string $rawBody, ?string $signature): bool
    {
        return $signature !== null && hash_equals($this->sign($rawBody), $signature);
    }

    public function parse(array $payload): array
    {
        return ['event_id' => (string) ($payload['event_id'] ?? ''), 'ref' => (string) ($payload['ref'] ?? ''), 'status' => in_array($payload['status'] ?? '', ['captured', 'failed', 'cancelled'], true) ? $payload['status'] : 'failed',
            'amount' => (float) ($payload['amount'] ?? 0), 'order_number' => $payload['order_number'] ?? null];
    }

    public function refund(Payment $payment, float $amount, string $reason): array
    {
        return ['ref' => 'FAKE-RF-'.strtoupper(Str::random(10))];
    }

    public function statement(Carbon $day): array
    {
        return self::$statement;
    }
}
