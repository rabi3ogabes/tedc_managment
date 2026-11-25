<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * What the platform needs from a payment gateway. Card data never touches TEDC: the buyer is sent to the gateway's hosted page and comes back,
 * and the gateway tells the server what happened in a signed message.
 */
interface PaymentGateway
{
    public function key(): string;

    /** Starts a payment. @return array{ref: string, redirect_url: string} */
    public function initiate(Order $order, string $returnUrl, string $callbackUrl): array;

    /**
     * Whether the signature of a callback is genuine. $signature is the value of the usual X-Signature header; $headers (names in lower case)
     * lets a gateway read the header its contract names instead.
     *
     * @param  array<string, string>  $headers
     */
    public function verify(string $rawBody, ?string $signature, array $headers = []): bool;

    /** The callback in the platform's own terms. @param  array<string, mixed>  $payload @return array{event_id: string, ref: string, status: 'captured'|'failed'|'cancelled', amount: float, order_number: ?string} */
    public function parse(array $payload): array;

    /** Asks the gateway to return money; the key stays the same however many times this refund is attempted. @return array{ref: string} */
    public function refund(Payment $payment, float $amount, string $reason, ?string $idempotencyKey = null): array;

    /** The gateway's own list of the day's transactions, for reconciliation. @return list<array{ref: string, status: string, amount: float}> */
    public function statement(Carbon $day): array;
}
