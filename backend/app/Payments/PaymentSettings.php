<?php

namespace App\Payments;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;

/** Settings → Payments: how long a seat is held while someone pays, whether a paid seat skips the manager's approval, voucher validity, and who is told. */
class PaymentSettings
{
    public const KEY = 'payments';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return ['hold_minutes' => 15, 'order_minutes' => 30, 'skip_manager_approval' => true, 'voucher_valid_days' => 180, 'voucher_reminder_days' => 14, 'default_vat_rate' => 0, 'seller_name_ar' => '', 'seller_name_en' => '', 'tax_id' => '', 'finance_email' => ''];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember('site.payments', 30, fn () => array_replace(self::defaults(), SiteSetting::find(self::KEY)?->value ?? []));
    }

    /** @param  array<string, mixed>  $d @return array<string, mixed> */
    public function save(array $d, ?string $by = null): array
    {
        $cur = $this->all();
        $new = [
            'hold_minutes' => max(5, min(120, (int) ($d['hold_minutes'] ?? $cur['hold_minutes']))), 'order_minutes' => max(10, min(240, (int) ($d['order_minutes'] ?? $cur['order_minutes']))),
            'skip_manager_approval' => (bool) ($d['skip_manager_approval'] ?? $cur['skip_manager_approval']), 'voucher_valid_days' => max(7, min(730, (int) ($d['voucher_valid_days'] ?? $cur['voucher_valid_days']))),
            'voucher_reminder_days' => max(1, min(60, (int) ($d['voucher_reminder_days'] ?? $cur['voucher_reminder_days']))), 'default_vat_rate' => max(0, min(100, (float) ($d['default_vat_rate'] ?? $cur['default_vat_rate']))),
            'seller_name_ar' => mb_substr(trim((string) ($d['seller_name_ar'] ?? $cur['seller_name_ar'])), 0, 160), 'seller_name_en' => mb_substr(trim((string) ($d['seller_name_en'] ?? $cur['seller_name_en'])), 0, 160),
            'tax_id' => mb_substr(trim((string) ($d['tax_id'] ?? $cur['tax_id'])), 0, 40), 'finance_email' => mb_substr(trim((string) ($d['finance_email'] ?? $cur['finance_email'])), 0, 160),
        ];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $new, 'updated_by' => $by]);
        Cache::forget('site.payments');

        return $new;
    }
}
