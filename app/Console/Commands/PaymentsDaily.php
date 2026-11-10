<?php

namespace App\Console\Commands;

use App\Payments\EntityPurchaseService;
use App\Payments\ReconciliationService;
use App\Services\FeatureSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PaymentsDaily extends Command
{
    protected $signature = 'tedc:payments-daily {--day= : reconcile this day (default: yesterday)}';

    protected $description = 'Reconcile yesterday with the gateway, expire vouchers and remind entities (Phase 16)';

    public function handle(ReconciliationService $reconciliation, EntityPurchaseService $entities, FeatureSettings $features): int
    {
        if (! $features->enabled('payments')) {
            return self::SUCCESS;
        }
        $r = $reconciliation->run($this->option('day') ? Carbon::parse($this->option('day')) : null);
        $this->line("reconciled {$r->day->toDateString()}: matched {$r->matched}, fixed {$r->fixed}, differences ".count($r->mismatches ?? []));
        $this->line('vouchers: '.json_encode($entities->sweep()));

        return self::SUCCESS;
    }
}
