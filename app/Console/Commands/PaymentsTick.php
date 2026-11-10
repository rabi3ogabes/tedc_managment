<?php

namespace App\Console\Commands;

use App\Models\CartItem;
use App\Payments\CheckoutService;
use App\Payments\SeatHolds;
use App\Services\FeatureSettings;
use Illuminate\Console\Command;

class PaymentsTick extends Command
{
    protected $signature = 'tedc:payments-tick';

    protected $description = 'Expire carts and unpaid orders, and release their seat holds (Phase 16, every minute)';

    public function handle(CheckoutService $checkout, SeatHolds $holds, FeatureSettings $features): int
    {
        if (! $features->enabled('payments')) {
            return self::SUCCESS;
        }
        $this->line('orders expired: '.$checkout->expire());
        $this->line('holds released: '.$holds->prune());
        CartItem::where('expires_at', '<', now()->subHour())->delete();

        return self::SUCCESS;
    }
}
