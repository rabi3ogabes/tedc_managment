<?php

namespace App\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PriceList;
use App\Models\Refund;
use App\Models\Registration;
use App\Models\SeatVoucher;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Payments\Gateways\GatewayFactory;
use App\Services\NotificationService;
use App\Services\RegistrationService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refunds under the group's policy: in full until N days before the start, a percentage until M days before, nothing after. A person asks, a finance user decides,
 * the gateway returns the money, the seat is released (a registration is cancelled, a voucher withdrawn) and a credit note is issued.
 */
class RefundService
{
    public const DEFAULT_POLICY = ['full_days' => 7, 'partial_days' => 3, 'partial_percent' => 50];

    public function __construct(private readonly GatewayFactory $gateways, private readonly InvoiceService $invoices, private readonly NotificationService $notifications, private readonly RegistrationService $registrations, private readonly SeatHolds $holds, private readonly CheckoutService $checkout) {}

    /** The share (0..100) refundable for a group today. */
    public function percentFor(TrainingGroup $group, ?PriceList $list = null): int
    {
        $p = array_replace(self::DEFAULT_POLICY, (array) ($list?->refund_policy ?? PriceList::where('group_id', $group->id)->first()?->refund_policy ?? PriceList::where('program_id', $group->program_id)->first()?->refund_policy ?? []));
        if (! $group->start_date) {
            return 100;
        }
        $days = (int) floor(now()->startOfDay()->diffInDays($group->start_date->copy()->startOfDay(), false));
        if ($days < 0 || ($days === 0 && $group->start_date->isPast())) {
            return 0;
        }

        return $days >= (int) $p['full_days'] ? 100 : ($days >= (int) $p['partial_days'] ? max(0, min(100, (int) $p['partial_percent'])) : 0);
    }

    /**
     * What could be refunded on an order now. Orders by people: each registered seat; by entities: each unused voucher.
     *
     * @return array{lines: list<array<string, mixed>>, amount: float}
     */
    public function quote(Order $o, ?array $only = null): array
    {
        $lines = [];
        $already = (float) Refund::where('order_id', $o->id)->whereIn('status', ['requested', 'approved', 'refunded'])->sum('amount');
        foreach ($o->items as $l) {
            $group = TrainingGroup::find($l['group_id']);
            if (! $group || (float) ($l['total'] ?? 0) <= 0) {
                continue;
            }
            $pct = $this->percentFor($group);
            if ($o->buyer_type === 'entity') {
                $perSeat = ($l['total'] ?? 0) / max(1, (int) $l['quantity']);
                foreach (SeatVoucher::where('order_id', $o->id)->where('group_id', $l['group_id'])->whereIn('status', ['available', 'assigned', 'expired'])->get() as $v) {
                    if ($only && ! in_array($v->id, $only, true)) {
                        continue;
                    }
                    $taken = Refund::where('order_id', $o->id)->whereIn('status', ['requested', 'approved', 'refunded'])->get()->contains(fn ($r) => in_array($v->id, array_column($r->items ?? [], 'voucher_id'), true));
                    if (! $taken) {
                        $lines[] = ['voucher_id' => $v->id, 'group_id' => $l['group_id'], 'title_ar' => $l['title_ar'], 'title_en' => $l['title_en'], 'gross' => round($perSeat, 2), 'percent' => $pct, 'refund' => round($perSeat * $pct / 100, 2)];
                    }
                }
            } elseif (($l['fulfilment'] ?? null) === 'registered' && (! $only || in_array($l['registration_id'], $only, true))) {
                $reg = Registration::find($l['registration_id']);
                $taken = Refund::where('order_id', $o->id)->whereIn('status', ['requested', 'approved', 'refunded'])->get()->contains(fn ($r) => in_array($l['registration_id'], array_column($r->items ?? [], 'registration_id'), true));
                if ($reg && ! in_array($reg->status, [Registration::STATUS_CANCELLED, Registration::STATUS_WITHDRAWN, Registration::STATUS_COMPLETED], true) && ! $taken) {
                    $lines[] = ['registration_id' => $l['registration_id'], 'group_id' => $l['group_id'], 'title_ar' => $l['title_ar'], 'title_en' => $l['title_en'], 'gross' => (float) $l['total'], 'percent' => $pct, 'refund' => round((float) $l['total'] * $pct / 100, 2)];
                }
            }
        }
        $amount = round(array_sum(array_column($lines, 'refund')), 2);

        return ['lines' => $lines, 'amount' => max(0.0, min($amount, round((float) $o->total - $already, 2)))];
    }

    /** @param  list<string>|null  $only  registration or voucher ids */
    public function request(User $by, Order $o, ?string $reason, ?array $only = null): Refund
    {
        if (! in_array($o->status, ['paid', 'partially_refunded'], true)) {
            throw new BusinessRuleException('Only a paid order can be refunded.', 'not_paid');
        }
        if (Refund::where('order_id', $o->id)->where('status', 'requested')->exists()) {
            throw new BusinessRuleException('A refund request for this order is already waiting for a decision.', 'refund_pending');
        }
        $q = $this->quote($o, $only);
        if ($q['amount'] <= 0) {
            throw new BusinessRuleException('Under the refund policy nothing can be refunded now.', 'not_refundable');
        }
        $payment = Payment::where('order_id', $o->id)->where('status', 'captured')->first();
        $r = Refund::create(['order_id' => $o->id, 'payment_id' => $payment?->id, 'items' => $q['lines'], 'amount' => $q['amount'], 'reason' => $reason ? mb_substr(strip_tags($reason), 0, 250) : null, 'status' => 'requested', 'requested_by' => $by->id]);
        $ids = User::whereHas('roles.permissions', fn ($w) => $w->where('slug', 'refunds.approve'))->pluck('id')->all();
        $ids && $this->notifications->broadcast($ids, 'refund.requested', ['ar' => 'طلب استرداد جديد', 'en' => 'New refund request'], ['ar' => 'الطلب '.$o->number.' — '.$q['amount'].' '.$o->currency, 'en' => 'Order '.$o->number.' — '.$q['amount'].' '.$o->currency], ['refund_id' => $r->id, 'route' => '/admin/finance'], raw: true);

        return $r;
    }

    public function decide(Refund $r, User $by, string $decision, ?string $note = null): Refund
    {
        if ($r->status !== 'requested') {
            throw new BusinessRuleException('This refund was already decided.', 'already_decided');
        }
        $o = Order::findOrFail($r->order_id);
        if ($decision === 'reject') {
            $r->update(['status' => 'rejected', 'approved_by' => $by->id, 'decision_note' => $note ? mb_substr($note, 0, 250) : null]);
            $this->tell($r, $o, false);

            return $r;
        }
        $r->update(['status' => 'approved', 'approved_by' => $by->id, 'decision_note' => $note ? mb_substr($note, 0, 250) : null]);
        try {
            $payment = $r->payment_id ? Payment::find($r->payment_id) : null;
            if ($payment && $payment->gateway_ref) {
                $res = $this->gateways->make()->refund($payment, (float) $r->amount, (string) ($r->reason ?? 'Refund '.$o->number));
                $r->update(['gateway_ref' => $res['ref']]);
            }
        } catch (Throwable $e) {
            $r->update(['status' => 'failed', 'decision_note' => 'Gateway: '.mb_substr($e->getMessage(), 0, 200)]);
            $this->checkout->tellFinance('A refund could not be sent to the gateway', "Order {$o->number}: {$e->getMessage()}");

            return $r;
        }
        DB::transaction(function () use ($r, $o) {
            foreach ((array) $r->items as $l) {
                if (! empty($l['registration_id'])) {
                    $reg = Registration::find($l['registration_id']);
                    if ($reg && in_array($reg->status, [Registration::STATUS_APPROVED, Registration::STATUS_PENDING, Registration::STATUS_PENDING_MANAGER, Registration::STATUS_WAITLISTED], true)) {
                        $this->registrations->transition($reg, Registration::STATUS_CANCELLED, null, 'Refunded');
                    }
                }
                if (! empty($l['voucher_id'])) {
                    SeatVoucher::whereKey($l['voucher_id'])->update(['status' => 'refunded']);
                    $this->holds->release('voucher', $l['voucher_id']);
                }
            }
            $r->update(['status' => 'refunded']);
            $paid = (float) Refund::where('order_id', $o->id)->where('status', 'refunded')->sum('amount');
            $o->update(['status' => $paid + 0.009 >= (float) $o->total ? 'refunded' : 'partially_refunded']);
            if ($o->status === 'refunded') {
                Payment::where('order_id', $o->id)->where('status', 'captured')->update(['status' => 'refunded']);
            }
        });
        $this->invoices->creditNote($r->refresh(), $o->refresh());
        $this->tell($r, $o, true);

        return $r;
    }

    private function tell(Refund $r, Order $o, bool $approved): void
    {
        $r->requested_by && $this->notifications->send($r->requested_by, 'refund.decided', ['ar' => $approved ? 'تمت الموافقة على الاسترداد' : 'رُفض طلب الاسترداد', 'en' => $approved ? 'Your refund was approved' : 'Your refund request was declined'],
            ['ar' => 'الطلب '.$o->number.($r->decision_note ? ' — '.$r->decision_note : ''), 'en' => 'Order '.$o->number.($r->decision_note ? ' — '.$r->decision_note : '')], ['refund_id' => $r->id, 'route' => '/portal/orders'], raw: true);
    }
}
