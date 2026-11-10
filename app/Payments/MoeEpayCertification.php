<?php

namespace App\Payments;

use App\Integrations\Contracts\Contract;
use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\Signature;
use App\Integrations\Contracts\Template;
use App\Integrations\IntegrationManager;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Payments\Gateways\GatewayFactory;
use App\Payments\Gateways\MoeEpayGateway;
use App\Payments\Gateways\PaymentGateway;
use App\Services\Channels\NotificationChannels;
use App\Services\FileStorage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The certification plan for the Ministry's payment gateway, run against the mock (the contract's recorded answers, or the Ministry's own
 * samples pasted into its test cases): a successful payment, a decline, a cancellation, a timeout, a repeated callback, an amount that does
 * not match, a refund, a partial refund and the import of the gateway's statement. It follows the contract in force, so a wrong edit shows
 * up as the case it breaks. It works on pretend orders that are rolled back, sends nothing anywhere, and refuses to run in production.
 */
class MoeEpayCertification
{
    public const CASES = [
        'success' => ['ar' => 'دفعة ناجحة', 'en' => 'A successful payment'],
        'decline' => ['ar' => 'رفض الدفع', 'en' => 'A declined payment'],
        'cancel' => ['ar' => 'إلغاء من المشتري', 'en' => 'A cancellation by the buyer'],
        'timeout' => ['ar' => 'انتهاء المهلة', 'en' => 'A timeout'],
        'duplicate_callback' => ['ar' => 'نداء مكرر', 'en' => 'A repeated callback'],
        'amount_mismatch' => ['ar' => 'مبلغ لا يطابق الطلب', 'en' => 'An amount that does not match the order'],
        'refund' => ['ar' => 'استرداد كلي', 'en' => 'A full refund'],
        'partial_refund' => ['ar' => 'استرداد جزئي', 'en' => 'A partial refund'],
        'reconciliation_import' => ['ar' => 'استيراد كشف المطابقة', 'en' => 'Importing the reconciliation statement'],
    ];

    private const BASE = 'https://mock.moe-epay.test/api';

    private const SECRET = 'certification-secret';

    /** @var array<string, mixed> */
    private array $settings = [];

    private PaymentGateway $gateway;

    private CheckoutService $checkout;

    private Contract $contract;

    private ?string $ministryKey = null;

    private int $counter = 0;

    private ?User $buyer = null;

    /** @var array<string, Payment> the payment each case left captured, for the refund cases */
    private array $captured = [];

    public function __construct(private readonly ContractStore $store) {}

    /**
     * @return array{ok: bool, ran_at: string, cases: list<array{id: string, title: array{ar: string, en: string}, ok: bool, steps: list<array{step: string, ok: bool, detail: string}>}>}
     *
     * @throws RuntimeException in production
     */
    public function run(): array
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The certification suite does not run in production: it works on pretend orders.');
        }
        $http = Http::getFacadeRoot();
        $channels = app(NotificationChannels::class);
        $level = DB::transactionLevel();
        Http::swap(new HttpFactory);
        $channels->choose([]);   // pretend orders tell nobody outside the system
        DB::beginTransaction();
        try {
            $this->arrange();
            $cases = [];
            foreach (self::CASES as $id => $title) {
                try {
                    $steps = $this->{'case'.Str::studly($id)}();
                } catch (Throwable $e) {
                    $steps = [['step' => 'run', 'ok' => false, 'detail' => 'The case could not be carried out: '.mb_substr($e->getMessage(), 0, 240)]];
                }
                $cases[] = ['id' => $id, 'title' => $title, 'ok' => $steps !== [] && ! in_array(false, array_column($steps, 'ok'), true), 'steps' => $steps];
            }
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            app()->forgetInstance(GatewayFactory::class);
            app()->forgetInstance(InvoiceService::class);
            app()->forgetInstance(CheckoutService::class);
            Http::swap($http);
            $channels->choose(null);
        }

        return ['ok' => ! in_array(false, array_column($cases, 'ok'), true), 'ran_at' => now()->toIso8601String(), 'cases' => $cases];
    }

    // ---- the cases ------------------------------------------------------------------------------------

    /** @return list<array{step: string, ok: bool, detail: string}> */
    private function caseSuccess(): array
    {
        $steps = [];
        [$order, $payment] = $this->start(150, $steps);
        $r = $this->callback('captured', $order, $payment, (float) $order->total);
        $steps[] = $this->check('The signed callback is accepted', $r['outcome'] === 'applied', "The outcome of the callback is '{$r['outcome']}', not applied.");
        $order->refresh();
        $steps[] = $this->check('The order is paid', $order->status === 'paid', "The order should be paid but is {$order->status}.");
        $steps[] = $this->check('The payment is captured', $payment->refresh()->status === 'captured', "The payment should be captured but is {$payment->status}.");
        $steps[] = $this->check('An invoice is issued', filled($order->invoice_no), 'The order has no invoice number.');
        $this->captured['success'] = $payment;

        return $steps;
    }

    /** @return list<array{step: string, ok: bool, detail: string}> */
    private function caseDecline(): array
    {
        $steps = [];
        [$order, $payment] = $this->start(80, $steps);
        $r = $this->callback('failed', $order, $payment, 80);
        $steps[] = $this->check('The callback is accepted', $r['outcome'] === 'applied', "The outcome of the callback is '{$r['outcome']}'.");
        $steps[] = $this->check('The order failed and nothing is charged', $order->refresh()->status === 'failed' && $payment->refresh()->status === 'failed', "The order is {$order->status} and the payment {$payment->status}; both should be failed.");
        $steps[] = $this->check('No invoice is issued', blank($order->invoice_no), 'A declined order has an invoice number.');

        return $steps;
    }

    /** @return list<array{step: string, ok: bool, detail: string}> */
    private function caseCancel(): array
    {
        $steps = [];
        [$order, $payment] = $this->start(60, $steps);
        $this->callback('cancelled', $order, $payment, 60);
        $steps[] = $this->check('The order is cancelled', $order->refresh()->status === 'cancelled', "The order should be cancelled but is {$order->status}.");
        $steps[] = $this->check('The payment is not captured', $payment->refresh()->status !== 'captured', 'A cancelled payment shows as captured.');

        return $steps;
    }

    /** @return list<array{step: string, ok: bool, detail: string}> */
    private function caseTimeout(): array
    {
        $steps = [];
        $slow = new MoeEpayGateway(['base_url' => 'https://timeout.moe-epay.test/api'] + $this->settings);
        Http::fake(['timeout.moe-epay.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')]);
        try {
            $slow->initiate($this->order(40), 'https://tedc.example/payments/return', 'https://tedc.example/api/v1/payments/callback/moe_epay');
            $steps[] = $this->check('A gateway that does not answer is an error', false, 'The call returned as if the gateway had answered.');
        } catch (ContractException $e) {
            $steps[] = $this->check('A gateway that does not answer is a timeout the buyer can retry', $e->errorCode === 'timeout' && $e->retryable, "The error is {$e->errorCode} (retryable: ".($e->retryable ? 'yes' : 'no').').');
            $steps[] = $this->check('The message is clear and holds no secret', ! str_contains($e->getMessage(), self::SECRET) && $e->getMessage() !== '', 'The message is empty or shows the secret.');
        }
        $stale = $this->order(25);
        $stale->forceFill(['status' => 'pending_payment', 'expires_at' => now()->subMinutes(5)])->save();
        $this->checkout->expire();
        $steps[] = $this->check('An order the buyer abandoned is cancelled when its time runs out', $stale->refresh()->status === 'cancelled', "The abandoned order is {$stale->status}, not cancelled.");

        return $steps;
    }

    /** @return list<array{step: string, ok: bool, detail: string}> */
    private function caseDuplicateCallback(): array
    {
        $steps = [];
        [$order, $payment] = $this->start(90, $steps);
        $first = $this->callback('captured', $order, $payment, 90, 'evt-dup-'.$order->number);
        $second = $this->callback('captured', $order, $payment, 90, 'evt-dup-'.$order->number);
        $steps[] = $this->check('The first callback is applied', $first['outcome'] === 'applied', "The first outcome is '{$first['outcome']}'.");
        $steps[] = $this->check('The same callback again is recognised and changes nothing', $second['outcome'] === 'duplicate', "The second outcome is '{$second['outcome']}', not duplicate.");
        $steps[] = $this->check('The order was paid once', $order->refresh()->status === 'paid' && DB::table('payment_events')->where('event_id', 'evt-dup-'.$order->number)->count() === 1, 'The order is not paid, or the event was recorded more than once.');

        return $steps;
    }

    /** @return list<array{step: string, ok: bool, detail: string}> */
    private function caseAmountMismatch(): array
    {
        $steps = [];
        [$order, $payment] = $this->start(120, $steps);
        $r = $this->callback('captured', $order, $payment, 119);
        $steps[] = $this->check('A callback for a different amount is not believed', $r['outcome'] === 'mismatch', "The outcome is '{$r['outcome']}', not mismatch.");
        $steps[] = $this->check('The order is not paid', $order->refresh()->status !== 'paid', 'An order was paid with the wrong amount.');

        return $steps;
    }

    /** @return list<array{step: string, ok: bool, detail: string}> */
    private function caseRefund(): array
    {
        $payment = $this->captured['success'] ?? null;
        if (! $payment) {
            return [$this->check('A paid order to refund', false, 'The successful payment case did not leave a paid order.')];
        }
        $answer = $this->gateway->refund($payment, (float) $payment->amount, 'Certification: full refund', 'tedc-refund-cert-full');

        return [$this->check('The gateway accepts a full refund and gives its reference', $answer['ref'] !== '', 'The answer holds no refund reference.')];
    }

    /** @return list<array{step: string, ok: bool, detail: string}> */
    private function casePartialRefund(): array
    {
        $payment = $this->captured['success'] ?? null;
        if (! $payment) {
            return [$this->check('A paid order to refund', false, 'The successful payment case did not leave a paid order.')];
        }
        $steps = [];
        $one = $this->gateway->refund($payment, 40.5, 'Certification: partial refund', 'tedc-refund-cert-part-1');
        $two = $this->gateway->refund($payment, 10, 'Certification: second partial refund', 'tedc-refund-cert-part-2');
        $steps[] = $this->check('Two partial refunds each get a reference', $one['ref'] !== '' && $two['ref'] !== '', 'A partial refund came back without a reference.');

        ContractFixtures::fake('moe_epay', 'https://rejects.moe-epay.test/api', ['refund' => 'rejected']);
        try {
            (new MoeEpayGateway(['base_url' => 'https://rejects.moe-epay.test/api'] + $this->settings))->refund($payment, 9999, 'Certification: too much', 'tedc-refund-cert-over');
            $steps[] = $this->check('A refund above what was captured is refused', false, 'The gateway accepted it.');
        } catch (ContractException $e) {
            $steps[] = $this->check('A refund above what was captured is refused with the gateway\'s reason', $e->errorCode === 'rejected' && $e->getMessage() !== '', "The error is {$e->errorCode}.");
        }

        return $steps;
    }

    /** @return list<array{step: string, ok: bool, detail: string}> */
    private function caseReconciliationImport(): array
    {
        $steps = [];
        $service = app(ReconciliationService::class);
        [$lostOrder, $lost] = $this->start(70, $steps);                       // paid at the gateway, but its callback never arrived
        [$knownOrder, $known] = $this->start(30, $steps);
        $this->callback('captured', $knownOrder, $known, 30);                // paid, and TEDC knows
        [$cols, $ok, $bad] = [$service->columns($this->contract), $this->wordFor('captured', 'success'), $this->wordFor('failed', 'failed')];
        $csv = "\xEF\xBB\xBF".implode(',', $cols)."\n"
            ."{$lost->gateway_ref},{$ok},70.00\n"
            ."{$known->gateway_ref},{$ok},30.00\n"
            ."CERT-UNKNOWN,{$ok},5.00\n"
            ."{$known->gateway_ref}-x,{$bad},12.00\n";

        $first = $service->importCsv(today(), $csv);
        $types = array_column((array) $first->mismatches, 'type');
        $steps[] = $this->check('A payment whose callback never arrived is completed from the file', $first->fixed === 1 && $lostOrder->refresh()->status === 'paid', "fixed={$first->fixed}, the order is {$lostOrder->status}.");
        $steps[] = $this->check('A payment TEDC already knows is matched', $first->matched === 1, "matched={$first->matched}.");
        $steps[] = $this->check('A payment TEDC has never heard of is reported', in_array('unknown_at_platform', $types, true), 'No unknown payment was reported.');
        $again = $service->importCsv(today(), $csv);
        $steps[] = $this->check('Importing the same file again changes nothing more', $again->fixed === 0 && $again->matched === 2, "second import: fixed={$again->fixed}, matched={$again->matched}.");
        $steps[] = $this->check('The result is kept for the day', PaymentReconciliation::whereDate('day', today())->count() === 1, 'The day has no single reconciliation record.');

        return $steps;
    }

    // ---- the mock and the pretend data ----------------------------------------------------------------

    /** The gateway's side: its settings (throwaway keys when the contract signs with keys), its recorded answers, and the services that use it. */
    private function arrange(): void
    {
        $this->contract = $this->store->active('moe_epay');
        $this->buyer = null;
        $this->counter = 0;
        $this->captured = [];
        $this->settings = ['base_url' => self::BASE, 'merchant_id' => 'CERT-MERCHANT', 'secret' => self::SECRET];
        $auth = $this->contract->auth();
        $inbound = (array) ($this->contract->inbound()['signature'] ?? []);
        if (($auth['algorithm'] ?? '') === 'rsa-sha256') {
            $this->settings[(string) ($auth['secret_setting'] ?? 'private_key')] = $this->keyPair()['private'];
        } elseif (isset($auth['secret_setting'])) {
            $this->settings[(string) $auth['secret_setting']] = self::SECRET;
        }
        if (($inbound['algorithm'] ?? '') === 'rsa-sha256') {
            $theirs = $this->keyPair();
            $this->ministryKey = $theirs['private'];
            $this->settings[(string) ($inbound['secret_setting'] ?? 'callback_key')] = $theirs['public'];
        } elseif (isset($inbound['secret_setting'])) {
            $this->settings[(string) $inbound['secret_setting']] = self::SECRET;
        }

        // The Ministry's own samples, when someone pasted them into the test cases, answer in place of the recorded ones.
        $answers = [];
        foreach ($this->contract->tests() as $case) {
            $response = $case['response'] ?? null;
            if (is_array($response) && ($response['status'] ?? 500) < 300 && isset($case['operation']) && ! isset($answers[$case['operation']])) {
                $answers[(string) $case['operation']] = ['status' => (int) $response['status'], 'body' => $response['body'] ?? []];
            }
        }
        ContractFixtures::fake('moe_epay', self::BASE, answers: $answers);

        $this->gateway = new MoeEpayGateway($this->settings);
        $factory = new class(app(IntegrationManager::class), $this->gateway) extends GatewayFactory
        {
            public function __construct(IntegrationManager $hub, private readonly PaymentGateway $pretend)
            {
                parent::__construct($hub);
            }

            public function make(): PaymentGateway
            {
                return $this->pretend;
            }
        };
        app()->instance(GatewayFactory::class, $factory);
        // An invoice is numbered but no file is written: the pretend order leaves nothing behind.
        app()->instance(InvoiceService::class, new class(app(FileStorage::class), app(PaymentSettings::class)) extends InvoiceService
        {
            public function issue(Order $order): Order
            {
                if (! $order->invoice_no) {
                    $order->update(['invoice_no' => $this->nextNumber('INV')]);
                }

                return $order;
            }
        });
        app()->forgetInstance(CheckoutService::class);
        $this->checkout = app(CheckoutService::class);
    }

    private function order(float $total): Order
    {
        $this->buyer ??= User::create(['name' => 'Certification buyer', 'email' => 'certification-'.Str::lower(Str::random(8)).'@example.test', 'password' => Str::random(40)]);

        return Order::create([
            'number' => 'CERT-'.now()->format('ymd').'-'.strtoupper(Str::random(6)), 'buyer_type' => 'user', 'user_id' => $this->buyer->id, 'items' => [], 'subtotal' => $total, 'discount' => 0, 'vat' => 0, 'total' => $total,
            'currency' => 'QAR', 'status' => 'pending_payment', 'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * A pretend order that goes through the gateway's start of a payment (and checks its answer).
     *
     * @param  list<array{step: string, ok: bool, detail: string}>  $steps
     * @return array{0: Order, 1: Payment}
     */
    private function start(float $total, array &$steps): array
    {
        $order = $this->order($total);
        $answer = $this->gateway->initiate($order, 'https://tedc.example/payments/return?order='.$order->number, 'https://tedc.example/api/v1/payments/callback/moe_epay');
        $steps[] = $this->check('The gateway starts the payment and gives its reference', $answer['ref'] !== '', 'The answer holds no payment reference (check the contract\'s response paths).');
        $steps[] = $this->check('It gives the page to send the buyer to', filter_var($answer['redirect_url'], FILTER_VALIDATE_URL) !== false, 'The answer holds no redirect address (check the contract\'s response paths).');
        $ref = $answer['ref'] !== '' ? $answer['ref'] : 'CERT-NOREF';
        while (Payment::where('gateway_ref', $ref)->exists()) {   // a mock that always answers the same reference: each pretend payment still needs its own
            $ref = ($answer['ref'] !== '' ? $answer['ref'] : 'CERT').'-'.(++$this->counter);
        }
        $payment = Payment::create(['order_id' => $order->id, 'gateway' => 'moe_epay', 'gateway_ref' => $ref, 'amount' => $total, 'status' => 'initiated']);

        return [$order, $payment];
    }

    /** The gateway's signed callback for an outcome, as the contract says it is shaped and signed, delivered to the real callback code. @return array{outcome: string, order: ?Order} */
    private function callback(string $outcome, Order $order, Payment $payment, float $amount, ?string $eventId = null): array
    {
        $sample = $this->contract->inbound()['samples'][$outcome] ?? null;
        if (! is_array($sample)) {
            throw new RuntimeException("The contract has no sample callback for \"{$outcome}\" (inbound.samples).");
        }
        $body = (string) json_encode(Template::renderValue($sample, ['event_id' => $eventId ?? 'evt-'.Str::random(12), 'payment_id' => $payment->gateway_ref, 'order_number' => $order->number, 'amount' => $amount]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $spec = (array) ($this->contract->inbound()['signature'] ?? []) ?: ['header' => 'X-Signature', 'algorithm' => 'hmac-sha256', 'encoding' => 'hex', 'payload' => '{{ body }}'];
        $stamp = (string) time();
        $key = ($spec['algorithm'] ?? '') === 'rsa-sha256' ? (string) $this->ministryKey : self::SECRET;
        $signature = Signature::make($spec, $key, $stamp, $body);
        $headers = [strtolower((string) $spec['header']) => $signature] + (! empty($spec['timestamp_header']) ? [strtolower((string) $spec['timestamp_header']) => $stamp] : []);

        return $this->checkout->callback('moe_epay', $body, $signature, $headers);
    }

    /** The word the statement uses for an outcome, from the transaction mapping's table. */
    private function wordFor(string $outcome, string $fallback): string
    {
        foreach ((array) ($this->contract->entity('transaction')['tables']['status'] ?? []) as $word => $mapsTo) {
            if ($mapsTo === $outcome) {
                return (string) $word;
            }
        }

        return $fallback;
    }

    /** @return array{private: string, public: string} */
    private function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);

        return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }

    /** @return array{step: string, ok: bool, detail: string} */
    private function check(string $step, bool $ok, string $detail): array
    {
        return ['step' => $step, 'ok' => $ok, 'detail' => $ok ? 'ok' : $detail];
    }
}
