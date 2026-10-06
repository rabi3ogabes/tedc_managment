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

    /** Whether the signature of a callback is genuine. @param  array<string, mixed>  $payload */
    public function verify(string $rawBody, ?string $signature): bool;

    /** The callback in the platform's own terms. @param  array<string, mixed>  $payload @return array{event_id: string, ref: string, status: 'captured'|'failed'|'cancelled', amount: float, order_number: ?string} */
    public function parse(array $payload): array;

    /** Asks the gateway to return money. @return array{ref: string} */
    public function refund(Payment $payment, float $amount, string $reason): array;

    /** The gateway's own list of the day's transactions, for reconciliation. @return list<array{ref: string, status: string, amount: float}> */
    public function statement(Carbon $day): array;
}
