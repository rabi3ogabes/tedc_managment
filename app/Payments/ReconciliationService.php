<?php

namespace App\Payments;

use App\Exceptions\BusinessRuleException;
use App\Integrations\Contracts\Contract;
use App\Integrations\Contracts\ContractStore;
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
        try {
            $rows = $this->gateways->make()->statement($day);
        } catch (Throwable $e) {
            $this->checkout->tellFinance('Payment reconciliation failed', $e->getMessage());

            return $this->save($day, 0, 0, [['type' => 'statement_unavailable', 'detail' => mb_substr($e->getMessage(), 0, 200)]]);
        }

        return $this->compare($day, $rows);
    }

    /**
     * The same comparison from the gateway's statement file (CSV, comma or semicolon, with or without a byte-order mark). The columns are
     * the ones of the gateway's statement answer (the contract's "transaction" mapping says which: payment_id, status, amount in the shipped one).
     */
    public function importCsv(Carbon $day, string $csv): PaymentReconciliation
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\R/', trim($csv)) ?: [], fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw new BusinessRuleException('The file has no transactions: it needs a header line and one line per transaction.', 'bad_statement');
        }
        if (count($lines) > 20001) {
            throw new BusinessRuleException('The file is too long: 20,000 transactions at most.', 'bad_statement');
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv($lines[0], $delimiter));
        $contract = app(ContractStore::class)->active('moe_epay');
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $cells = array_slice(str_getcsv($line, $delimiter), 0, count($header));
            $m = $contract->map('transaction', array_combine($header, array_pad($cells, count($header), '')));
            if (($m['ref'] ?? '') !== '') {
                $rows[] = ['ref' => (string) $m['ref'], 'status' => strtolower((string) ($m['status'] ?? '')), 'amount' => (float) ($m['amount'] ?? 0)];
            }
        }
        if ($rows === []) {
            throw new BusinessRuleException('The file is not a statement of the gateway: no line has a payment reference. Expected columns: '.implode(', ', $this->columns($contract)).'.', 'bad_statement');
        }

        return $this->compare($day, $rows);
    }

    /** The column names the shipped or edited transaction mapping reads. @return list<string> */
    public function columns(Contract $contract): array
    {
        $out = [];
        foreach (['ref', 'status', 'amount'] as $field) {
            $spec = $contract->entity('transaction')['fields'][$field] ?? $field;
            $path = is_string($spec) ? $spec : ($spec['path'] ?? ($spec['paths'][0] ?? $field));
            $out[] = ltrim((string) preg_replace('/^\$\./', '', (string) $path), '$.');
        }

        return $out;
    }

    /** @param  list<array{ref: string, status: string, amount: float}>  $rows */
    public function compare(Carbon $day, array $rows): PaymentReconciliation
    {
        $matched = $fixed = 0;
        $mismatches = [];
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
