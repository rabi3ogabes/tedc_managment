<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Payments\Gateways\GatewayFactory;
use Illuminate\Support\Carbon;
use Throwable;

/** Compares the gateway's list of the day with what the platform recorded: payments the platform missed are completed, anything else that differs is reported to finance. */
class ReconciliationService
{
    public function __construct(private readonly GatewayFactory $gateways, private readonly CheckoutService $checkout) {}

    public function run(?Carbon $day = null): PaymentReconciliation
    {
        $day ??= now()->subDay();
        $matched = $fixed = 0;
        $mismatches = [];
        try {
            $rows = $this->gateways->make()->statement($day);
        } catch (Throwable $e) {
            $this->checkout->tellFinance('Payment reconciliation failed', $e->getMessage());

            return $this->save($day, 0, 0, [['type' => 'statement_unavailable', 'detail' => mb_substr($e->getMessage(), 0, 200)]]);
        }
        $seen = [];
        foreach ($rows as $r) {
            $seen[] = $r['ref'];
            $p = Payment::where('gateway_ref', $r['ref'])->first();
            if (! $p) {
                $mismatches[] = ['type' => 'unknown_at_platform', 'ref' => $r['ref'], 'amount' => $r['amount']];

                continue;
            }
            $captured = in_array($r['status'], ['captured', 'success', 'paid'], true);
            if ($captured && abs($r['amount'] - (float) $p->amount) > 0.009) {
                $mismatches[] = ['type' => 'amount_differs', 'ref' => $r['ref'], 'platform' => (float) $p->amount, 'gateway' => $r['amount']];
            } elseif ($captured && $p->status !== 'captured' && $p->status !== 'refunded') {
                $order = Order::find($p->order_id);
                if ($order && in_array($order->status, ['pending_payment', 'failed', 'cancelled'], true)) {
                    $this->checkout->markPaid($order, $p);   // the callback never arrived
                    $fixed++;
                } else {
                    $mismatches[] = ['type' => 'status_differs', 'ref' => $r['ref'], 'platform' => $p->status, 'gateway' => $r['status']];
                }
            } elseif (! $captured && $p->status === 'captured') {
                $mismatches[] = ['type' => 'status_differs', 'ref' => $r['ref'], 'platform' => $p->status, 'gateway' => $r['status']];
            } else {
                $matched++;
            }
        }
        foreach (Payment::where('status', 'captured')->whereDate('captured_at', $day->toDateString())->get() as $p) {
            if ($p->gateway_ref && ! in_array($p->gateway_ref, $seen, true)) {
                $mismatches[] = ['type' => 'missing_at_gateway', 'ref' => $p->gateway_ref, 'amount' => (float) $p->amount];
            }
        }
        if ($mismatches) {
            $this->checkout->tellFinance('Payment reconciliation: differences found', count($mismatches).' difference(s) for '.$day->toDateString().'.');
        }

        return $this->save($day, $matched, $fixed, $mismatches);
    }

    /** @param  list<array<string, mixed>>  $mismatches */
    private function save(Carbon $day, int $matched, int $fixed, array $mismatches): PaymentReconciliation
    {
        $row = PaymentReconciliation::whereDate('day', $day->toDateString())->first() ?? new PaymentReconciliation(['day' => $day->toDateString()]);
        $row->fill(['matched' => $matched, 'fixed' => $fixed, 'mismatches' => $mismatches])->save();

        return $row;
    }
}
