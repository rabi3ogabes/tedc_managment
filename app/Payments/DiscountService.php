<?php

namespace App\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Discount codes: dates, how many times in all and per person, and what they apply to (everything, one programme, one group). */
class DiscountService
{
    public function find(string $code): ?DiscountCode
    {
        return DiscountCode::whereRaw('upper(code) = ?', [strtoupper(trim($code))])->first();
    }

    /** @param  list<array{group_id: string, program_id: string}>  $lines */
    public function validate(string $code, User $user, array $lines): DiscountCode
    {
        $c = $this->find($code);
        if (! $c || ! $c->is_active) {
            throw new BusinessRuleException('This discount code is not valid.', 'code_invalid');
        }
        if (($c->valid_from && $c->valid_from->isFuture()) || ($c->valid_to && $c->valid_to->isPast())) {
            throw new BusinessRuleException('This discount code is not valid today.', 'code_expired');
        }
        if ($c->usage_limit !== null && $c->used >= $c->usage_limit) {
            throw new BusinessRuleException('This discount code has been used up.', 'code_used_up');
        }
        $mine = Order::where('user_id', $user->id)->where('discount_code', $c->code)->whereIn('status', ['paid', 'pending_payment', 'partially_refunded'])->count();
        if ($mine >= $c->per_user_limit) {
            throw new BusinessRuleException('You have already used this discount code.', 'code_user_limit');
        }
        if ($c->scope !== 'all' && ! array_filter($lines, fn ($l) => $this->applies($c, $l))) {
            throw new BusinessRuleException('This discount code does not apply to what is in your cart.', 'code_scope');
        }

        return $c;
    }

    /** @param  array{group_id: string, program_id: string}  $line */
    public function applies(DiscountCode $c, array $line): bool
    {
        return $c->scope === 'all' || ($c->scope === 'program' && $c->scope_id === $line['program_id']) || ($c->scope === 'group' && $c->scope_id === $line['group_id']);
    }

    /** The discount on one line's net amount. */
    public function lineDiscount(DiscountCode $c, array $line, float $net, float $subtotalOfApplicable): float
    {
        if (! $this->applies($c, $line)) {
            return 0.0;
        }
        if ($c->type === 'percent') {
            return round($net * min(100.0, $c->value) / 100, 2);
        }

        // A fixed amount is spread over the applicable lines in proportion to their value.
        return $subtotalOfApplicable > 0 ? round(min($net, $c->value * $net / $subtotalOfApplicable), 2) : 0.0;
    }

    /** One more use, counted once per paid order. */
    public function consume(string $code): void
    {
        DB::table('discount_codes')->whereRaw('upper(code) = ?', [strtoupper($code)])->increment('used');
    }
}
