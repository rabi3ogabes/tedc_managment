<?php

namespace App\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Employee;
use App\Models\EntityAccount;
use App\Models\Registration;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\Eligibility\EligibilityEngine;
use Illuminate\Support\Facades\DB;

/** The cart: one for a person, one for each entity they administer. Adding a group holds its seats for a few minutes. */
class CartService
{
    public function __construct(private readonly PricingService $pricing, private readonly SeatHolds $holds, private readonly DiscountService $discounts, private readonly EligibilityEngine $eligibility, private readonly PaymentSettings $settings) {}

    public function cartFor(User $user, ?EntityAccount $entity = null): Cart
    {
        return Cart::firstOrCreate(['user_id' => $user->id, 'entity_account_id' => $entity?->id]);
    }

    public function add(Cart $cart, TrainingGroup $group, int $quantity = 1): CartItem
    {
        $entity = $cart->entity_account_id !== null;
        $quantity = $entity ? max(1, min(500, $quantity)) : 1;
        $group->loadMissing('program');
        if (! in_array($group->program->status, ['published', 'registration_open', 'in_progress'], true) || ! $group->isRegistrationOpen()) {
            throw new BusinessRuleException('Registration for this group is closed.', 'registration_closed');
        }
        $user = $cart->user ?? User::find($cart->user_id);
        $employee = Employee::where('user_id', $user->id)->first();
        if (! $entity) {
            if (! $employee) {
                throw new BusinessRuleException('Your account has no employee profile yet.', 'no_profile');
            }
            if (Registration::where('program_id', $group->program_id)->where('employee_id', $employee->id)->whereNotIn('status', [Registration::STATUS_CANCELLED, Registration::STATUS_REJECTED, Registration::STATUS_WITHDRAWN])->exists()) {
                throw new BusinessRuleException('You are already registered in this programme.', 'duplicate');
            }
            if (! $this->eligibility->evaluate($group->program, $employee)->eligible) {
                throw new BusinessRuleException('You are not eligible for this programme.', 'not_eligible');
            }
        }
        // The group row is locked while the free seats are counted and the hold is placed, so two buyers cannot both take the last seat.
        $item = DB::transaction(function () use ($cart, $group, $quantity) {
            TrainingGroup::whereKey($group->id)->lockForUpdate()->first();
            $existing = CartItem::where(['cart_id' => $cart->id, 'group_id' => $group->id])->first();
            $mine = $existing ? $this->holds->held($group->id, 'cart', $existing->id) : 0;
            if ($group->refresh()->seatsAvailable() + $mine < $quantity) {
                throw new BusinessRuleException('Not enough free seats in this group.', 'no_seats');
            }
            $expires = now()->addMinutes((int) $this->settings->all()['hold_minutes']);
            $item = CartItem::updateOrCreate(['cart_id' => $cart->id, 'group_id' => $group->id], ['quantity' => $quantity, 'expires_at' => $expires]);
            $this->holds->place($group->id, 'cart', $item->id, $quantity, $expires);

            return $item;
        });

        return $item;
    }

    public function remove(Cart $cart, string $groupId): void
    {
        CartItem::where(['cart_id' => $cart->id, 'group_id' => $groupId])->get()->each(function (CartItem $i) {
            $this->holds->release('cart', $i->id);
            $i->delete();
        });
    }

    public function clear(Cart $cart): void
    {
        foreach (CartItem::where('cart_id', $cart->id)->get() as $i) {
            $this->holds->release('cart', $i->id);
            $i->delete();
        }
        $cart->update(['discount_code' => null]);
    }

    public function applyCode(Cart $cart, ?string $code): Cart
    {
        if ($code === null || trim($code) === '') {
            $cart->update(['discount_code' => null]);

            return $cart;
        }
        $user = User::findOrFail($cart->user_id);
        $lines = $this->lines($cart, false);
        $c = $this->discounts->validate($code, $user, array_map(fn ($l) => ['group_id' => $l['group_id'], 'program_id' => $l['program_id']], $lines));
        $cart->update(['discount_code' => $c->code]);

        return $cart;
    }

    /** Lines with their prices; lines whose hold ran out are dropped unless $keepExpired. @return list<array<string, mixed>> */
    public function lines(Cart $cart, bool $dropExpired = true): array
    {
        $user = User::findOrFail($cart->user_id);
        $employee = $cart->entity_account_id ? null : Employee::where('user_id', $user->id)->first();
        $out = [];
        foreach (CartItem::where('cart_id', $cart->id)->get() as $i) {
            if ($dropExpired && $i->expires_at->isPast()) {
                $this->holds->release('cart', $i->id);
                $i->delete();

                continue;
            }
            $g = TrainingGroup::with('program')->find($i->group_id);
            if (! $g) {
                continue;
            }
            $p = $this->pricing->resolve($g, $employee, $cart->entity_account_id !== null);
            $out[] = ['item_id' => $i->id, 'group_id' => $g->id, 'program_id' => $g->program_id, 'title_ar' => $g->program->title_ar.' — '.$g->displayTitle('ar'), 'title_en' => $g->program->title_en.' — '.$g->displayTitle('en'), 'start_date' => $g->start_date?->toDateString(),
                'quantity' => $i->quantity, 'unit_price' => $p['price'], 'vat_rate' => $p['vat_rate'], 'currency' => $p['currency'], 'category' => $p['category'], 'rule' => $p['rule'], 'expires_at' => $i->expires_at->toIso8601String()];
        }

        return $out;
    }

    /** Totals with the discount code and VAT. @return array{lines: list<array<string, mixed>>, subtotal: float, discount: float, vat: float, total: float, currency: string, code: ?string} */
    public function totals(Cart $cart): array
    {
        $lines = $this->lines($cart);
        $code = $cart->discount_code ? $this->discounts->find($cart->discount_code) : null;
        $applicable = 0.0;
        foreach ($lines as $l) {
            if ($code && $this->discounts->applies($code, $l)) {
                $applicable += $l['unit_price'] * $l['quantity'];
            }
        }
        $subtotal = $discount = $vat = 0.0;
        foreach ($lines as $k => $l) {
            $gross = round($l['unit_price'] * $l['quantity'], 2);
            $d = $code ? $this->discounts->lineDiscount($code, $l, $gross, $applicable) : 0.0;
            $net = max(0.0, $gross - $d);
            $v = round($net * $l['vat_rate'] / 100, 2);
            $lines[$k] += ['gross' => $gross, 'discount' => $d, 'net' => $net, 'vat' => $v, 'total' => round($net + $v, 2)];
            $subtotal += $gross;
            $discount += $d;
            $vat += $v;
        }

        return ['lines' => $lines, 'subtotal' => round($subtotal, 2), 'discount' => round($discount, 2), 'vat' => round($vat, 2), 'total' => round($subtotal - $discount + $vat, 2), 'currency' => $lines[0]['currency'] ?? 'QAR', 'code' => $code?->code];
    }
}
