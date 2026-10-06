<?php

namespace App\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Registration;
use App\Models\SeatVoucher;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Payments\Gateways\GatewayFactory;
use App\Services\NotificationService;
use App\Services\RegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cart → order → the gateway's hosted page → the gateway's signed message → seats confirmed (a person) or vouchers made (an entity), an invoice and a receipt.
 * The buyer's return to the site only shows what happened; the money is believed only from the signed server-to-server message, once.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartService $carts, private readonly SeatHolds $holds, private readonly GatewayFactory $gateways, private readonly InvoiceService $invoices, private readonly DiscountService $discounts,
        private readonly NotificationService $notifications, private readonly RegistrationService $registrations, private readonly PaymentSettings $settings,
    ) {}

    /** @return array{order: Order, redirect_url: ?string} */
    public function start(User $user, Cart $cart): array
    {
        $t = $this->carts->totals($cart);
        if (! $t['lines']) {
            throw new BusinessRuleException('Your cart is empty.', 'empty_cart');
        }
        if ($cart->discount_code) {   // the code is checked again at the moment of paying
            $this->discounts->validate($cart->discount_code, $user, array_map(fn ($l) => ['group_id' => $l['group_id'], 'program_id' => $l['program_id']], $t['lines']));
        }
        $entity = $cart->entity_account_id !== null;
        $order = DB::transaction(function () use ($user, $cart, $t, $entity) {
            $order = Order::create([
                'number' => $this->number(), 'buyer_type' => $entity ? 'entity' : 'user', 'user_id' => $user->id, 'entity_account_id' => $cart->entity_account_id, 'items' => $t['lines'], 'subtotal' => $t['subtotal'], 'discount' => $t['discount'], 'vat' => $t['vat'], 'total' => $t['total'],
                'currency' => $t['currency'], 'discount_code' => $t['code'], 'status' => 'pending_payment', 'expires_at' => now()->addMinutes((int) $this->settings->all()['order_minutes']),
            ]);
            foreach ($t['lines'] as $l) {
                $this->holds->release('cart', $l['item_id']);
                $this->holds->place($l['group_id'], 'order', $order->id, $l['quantity'], $order->expires_at);
            }
            CartItem::where('cart_id', $cart->id)->delete();
            $cart->update(['discount_code' => null]);

            return $order;
        });
        if ($order->total <= 0) {
            $this->markPaid($order, null);   // nothing to pay: free for this buyer, or fully discounted

            return ['order' => $order->refresh(), 'redirect_url' => null];
        }
        try {
            $gw = $this->gateways->make();
            $payment = Payment::create(['order_id' => $order->id, 'gateway' => $gw->key(), 'amount' => $order->total, 'status' => 'initiated']);
            $web = rtrim((string) config('tedc.web_url'), '/');
            $r = $gw->initiate($order, $web.'/payments/return?order='.$order->number, rtrim((string) config('app.url'), '/').'/api/v1/payments/callback/'.$gw->key());
            $payment->update(['gateway_ref' => $r['ref']]);
        } catch (Throwable $e) {
            $this->fail($order, 'failed', 'gateway_unreachable');
            if ($e instanceof BusinessRuleException) {
                throw $e;
            }
            throw new BusinessRuleException('The payment service is not available right now. Nothing was charged. Please try again.', 'gateway_unavailable');
        }

        return ['order' => $order, 'redirect_url' => $r['redirect_url']];
    }

    private function number(): string
    {
        do {
            $n = 'ORD-'.now()->format('ym').'-'.strtoupper(Str::random(6));
        } while (Order::where('number', $n)->exists());

        return $n;
    }

    /**
     * The gateway's server-to-server message. Idempotent: the same event twice changes nothing.
     *
     * @return array{outcome: string, order: ?Order}
     */
    public function callback(string $gateway, string $rawBody, ?string $signature): array
    {
        $gw = $this->gateways->make();
        $valid = $gw->verify($rawBody, $signature);
        $payload = json_decode($rawBody, true) ?: [];
        $e = $gw->parse($payload);
        if (! $valid) {   // recorded under its own key, so a forged message can never use up a real event id
            PaymentEvent::firstOrCreate(['gateway' => $gw->key(), 'event_id' => 'rejected:'.sha1($rawBody.'|'.$signature), 'kind' => 'callback'], ['signature_valid' => false, 'outcome' => 'rejected']);

            return ['outcome' => 'rejected', 'order' => null];
        }
        $event = PaymentEvent::firstOrCreate(['gateway' => $gw->key(), 'event_id' => $e['event_id'] !== '' ? $e['event_id'] : sha1($rawBody), 'kind' => 'callback'], ['signature_valid' => true, 'outcome' => null]);
        if (! $event->wasRecentlyCreated && $event->outcome !== null) {
            return ['outcome' => 'duplicate', 'order' => Payment::where('gateway_ref', $e['ref'])->first()?->order];
        }
        $payment = Payment::where('gateway_ref', $e['ref'])->first();
        if (! $payment) {
            $event->update(['outcome' => 'ignored']);

            return ['outcome' => 'ignored', 'order' => null];
        }
        $order = Order::findOrFail($payment->order_id);
        $outcome = DB::transaction(function () use ($order, $payment, $e) {
            $o = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($o->status === 'paid' || in_array($o->status, ['refunded', 'partially_refunded'], true)) {
                return 'duplicate';
            }
            $payment->update(['signature_valid' => true, 'raw' => ['status' => $e['status'], 'amount' => $e['amount'], 'event' => $e['event_id']]]);
            if ($e['status'] === 'captured') {
                if (abs($e['amount'] - (float) $o->total) > 0.009) {
                    $payment->update(['status' => 'failed']);
                    $this->fail($o, 'failed', 'amount_mismatch');
                    $this->tellFinance('Payment amount mismatch', "Order {$o->number}: expected {$o->total}, gateway reported {$e['amount']}.");

                    return 'mismatch';
                }
                $this->markPaid($o, $payment);

                return 'applied';
            }
            $payment->update(['status' => 'failed']);
            $this->fail($o, $e['status'] === 'cancelled' ? 'cancelled' : 'failed', 'gateway_'.$e['status']);

            return 'applied';
        });
        $event->update(['outcome' => $outcome]);

        return ['outcome' => $outcome, 'order' => $order->refresh()];
    }

    /** Pending orders whose time ran out are cancelled and their seats released. */
    public function expire(): int
    {
        $n = 0;
        Order::where('status', 'pending_payment')->where('expires_at', '<', now())->get()->each(function (Order $o) use (&$n) {
            $this->fail($o, 'cancelled', 'expired');
            $n++;
        });
        $this->holds->prune();

        return $n;
    }

    public function fail(Order $o, string $status, string $reason): void
    {
        $o->update(['status' => $status]);
        $this->holds->release('order', $o->id);
        Payment::where('order_id', $o->id)->whereIn('status', ['initiated', 'authorized'])->update(['status' => 'failed']);
        if ($o->user_id && $reason !== 'expired') {
            $this->notifications->send($o->user_id, 'order.failed', ['ar' => 'لم تكتمل عملية الدفع', 'en' => 'Your payment was not completed'], ['ar' => 'الطلب '.$o->number.' — لم يُخصم أي مبلغ. يمكنك المحاولة مرة أخرى.', 'en' => 'Order '.$o->number.' — nothing was charged. You can try again.'], ['order_id' => $o->id, 'route' => '/portal/orders'], raw: true);
        }
    }

    /** Order paid: seats confirmed or vouchers made, invoice issued, receipt sent. */
    public function markPaid(Order $o, ?Payment $payment): void
    {
        DB::transaction(function () use ($o, $payment) {
            $o->update(['status' => 'paid', 'paid_at' => now()]);
            $payment?->update(['status' => 'captured', 'captured_at' => now()]);
            $this->holds->release('order', $o->id);
            $items = $o->items;
            $o->buyer_type === 'entity' ? $this->makeVouchers($o, $items) : $items = $this->register($o, $items);
            $o->update(['items' => $items]);
            if ($o->discount_code) {
                $this->discounts->consume($o->discount_code);
            }
        });
        $o = $this->invoices->issue($o->refresh());
        $this->notifications->send($o->user_id, 'order.paid', ['ar' => 'تم استلام دفعتك', 'en' => 'Payment received'], ['ar' => 'إيصال الطلب '.$o->number.' — الفاتورة '.$o->invoice_no, 'en' => 'Receipt for order '.$o->number.' — invoice '.$o->invoice_no], ['order_id' => $o->id, 'route' => '/portal/orders'], raw: true);
    }

    /** @param  list<array<string, mixed>>  $items @return list<array<string, mixed>> */
    private function register(Order $o, array $items): array
    {
        $employee = Employee::where('user_id', $o->user_id)->first();
        foreach ($items as $k => $l) {
            $group = TrainingGroup::with('program')->find($l['group_id']);
            try {
                if (! $employee || ! $group) {
                    throw new BusinessRuleException('Profile or group missing.', 'missing');
                }
                $reg = $this->registrations->register($group->program, $employee, Registration::SOURCE_SELF, User::find($o->user_id), null, false, $group);
                if ($this->settings->all()['skip_manager_approval'] && in_array($reg->status, [Registration::STATUS_PENDING_MANAGER, Registration::STATUS_PENDING], true)) {
                    if ($reg->status === Registration::STATUS_PENDING_MANAGER) {
                        $reg = $this->registrations->transition($reg, Registration::STATUS_PENDING);
                    }
                    $reg = $this->registrations->transition($reg, Registration::STATUS_APPROVED, null, 'Paid', 'Paid registration');
                }
                $items[$k]['registration_id'] = $reg->id;
                $items[$k]['fulfilment'] = 'registered';
            } catch (Throwable $e) {
                $items[$k]['fulfilment'] = 'failed';
                $items[$k]['fulfilment_error'] = $e->getMessage();
                $this->tellFinance('A paid registration needs attention', "Order {$o->number}: {$e->getMessage()}");
            }
        }

        return $items;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function makeVouchers(Order $o, array $items): void
    {
        $days = (int) $this->settings->all()['voucher_valid_days'];
        foreach ($items as $l) {
            $group = TrainingGroup::find($l['group_id']);
            $expires = now()->addDays($days);
            if ($group?->start_date && $group->start_date->endOfDay()->lt($expires)) {
                $expires = $group->start_date->copy()->endOfDay();   // a voucher cannot outlive the start of the group
            }
            if ($expires->isPast()) {
                $expires = now()->addDays(7);
            }
            for ($i = 0; $i < (int) $l['quantity']; $i++) {
                $v = SeatVoucher::create(['order_id' => $o->id, 'entity_account_id' => $o->entity_account_id, 'group_id' => $l['group_id'], 'code' => $this->code(), 'status' => 'available', 'expires_at' => $expires]);
                $this->holds->place($l['group_id'], 'voucher', $v->id, 1, $expires);
            }
        }
    }

    private function code(): string
    {
        do {
            $c = strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
            $c = str_replace(['0', 'O', '1', 'I'], ['7', 'X', '8', 'Y'], $c);
        } while (SeatVoucher::where('code', $c)->exists());

        return $c;
    }

    public function tellFinance(string $title, string $body): void
    {
        $ids = User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'finance.reports'))->pluck('id')->all();
        $ids && $this->notifications->broadcast($ids, 'payment.attention', ['ar' => $title, 'en' => $title], ['ar' => $body, 'en' => $body], ['route' => '/admin/finance'], raw: true);
    }
}
