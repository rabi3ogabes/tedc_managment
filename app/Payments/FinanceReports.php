<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Money in and out: revenue by programme, category and entity, refunds, and what is still waiting to be paid. */
class FinanceReports
{
    /** @return array<string, mixed> */
    public function summary(?string $from = null, ?string $to = null): array
    {
        $from = $from ? Carbon::parse($from)->startOfDay() : now()->startOfYear();
        $to = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        $paid = Order::whereIn('status', ['paid', 'partially_refunded', 'refunded'])->whereBetween('paid_at', [$from, $to])->get();
        $refunds = Refund::where('status', 'refunded')->whereBetween('updated_at', [$from, $to])->get();
        $byProgram = [];
        $byCategory = [];
        $byEntity = [];
        foreach ($paid as $o) {
            foreach ($o->items as $l) {
                $key = $l['program_id'] ?? 'x';
                $byProgram[$key]['program_id'] = $key;
                $byProgram[$key]['title_en'] = explode(' — ', (string) ($l['title_en'] ?? ''))[0];
                $byProgram[$key]['title_ar'] = explode(' — ', (string) ($l['title_ar'] ?? ''))[0];
                $byProgram[$key]['seats'] = ($byProgram[$key]['seats'] ?? 0) + (int) ($l['quantity'] ?? 1);
                $byProgram[$key]['revenue'] = round(($byProgram[$key]['revenue'] ?? 0) + (float) ($l['total'] ?? 0), 2);
                $cat = (string) ($l['category'] ?? 'any');
                $byCategory[$cat] = ['category' => $cat, 'seats' => ($byCategory[$cat]['seats'] ?? 0) + (int) ($l['quantity'] ?? 1), 'revenue' => round(($byCategory[$cat]['revenue'] ?? 0) + (float) ($l['total'] ?? 0), 2)];
            }
            if ($o->entity_account_id) {
                $byEntity[$o->entity_account_id] = ['entity_id' => $o->entity_account_id, 'orders' => ($byEntity[$o->entity_account_id]['orders'] ?? 0) + 1, 'revenue' => round(($byEntity[$o->entity_account_id]['revenue'] ?? 0) + (float) $o->total, 2)];
            }
        }
        $names = DB::table('entity_accounts')->whereIn('id', array_keys($byEntity))->pluck('name_en', 'id');
        foreach ($byEntity as $id => $row) {
            $byEntity[$id]['name'] = $names[$id] ?? '';
        }
        $gross = round($paid->sum('total'), 2);
        $refunded = round($refunds->sum('amount'), 2);
        $pending = Order::where('status', 'pending_payment')->where('expires_at', '>', now());

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => 'QAR',
            'totals' => ['orders' => $paid->count(), 'gross' => $gross, 'vat' => round($paid->sum('vat'), 2), 'discounts' => round($paid->sum('discount'), 2), 'refunded' => $refunded, 'net' => round($gross - $refunded, 2), 'outstanding' => round((float) $pending->sum('total'), 2), 'outstanding_orders' => $pending->count()],
            'by_program' => array_values($byProgram), 'by_category' => array_values($byCategory), 'by_entity' => array_values($byEntity),
            'refunds' => $refunds->map(fn ($r) => ['id' => $r->id, 'order_id' => $r->order_id, 'amount' => (float) $r->amount, 'credit_note_no' => $r->credit_note_no, 'reason' => $r->reason])->values()->all(),
        ];
    }

    /** A document for the shared exporter. @param  array<string, mixed>  $s @return array<string, mixed> */
    public function document(array $s, bool $ar): array
    {
        $t = $s['totals'];
        $l = fn (string $en, string $arabic) => $ar ? $arabic : $en;

        return ['title' => $l('Financial report', 'التقرير المالي'), 'subtitle' => $s['from'].' → '.$s['to'].' ('.$s['currency'].')', 'sections' => [
            ['heading' => $l('Summary', 'الملخص'), 'table' => ['head' => [$l('Item', 'البند'), $l('Amount', 'المبلغ')], 'rows' => [[$l('Paid orders', 'طلبات مدفوعة'), $t['orders']], [$l('Gross', 'الإجمالي'), $t['gross']], [$l('VAT', 'الضريبة'), $t['vat']], [$l('Discounts', 'الخصومات'), $t['discounts']], [$l('Refunded', 'المردود'), $t['refunded']], [$l('Net', 'الصافي'), $t['net']], [$l('Outstanding', 'غير مسدد'), $t['outstanding']]]]],
            ['heading' => $l('Revenue by programme', 'الإيراد حسب البرنامج'), 'table' => ['head' => [$l('Programme', 'البرنامج'), $l('Seats', 'المقاعد'), $l('Revenue', 'الإيراد')], 'rows' => array_map(fn ($r) => [$ar ? $r['title_ar'] : $r['title_en'], $r['seats'], $r['revenue']], $s['by_program'])]],
            ['heading' => $l('Revenue by category', 'الإيراد حسب الفئة'), 'table' => ['head' => [$l('Category', 'الفئة'), $l('Seats', 'المقاعد'), $l('Revenue', 'الإيراد')], 'rows' => array_map(fn ($r) => [$r['category'], $r['seats'], $r['revenue']], $s['by_category'])]],
            ['heading' => $l('Revenue by entity', 'الإيراد حسب الجهة'), 'table' => ['head' => [$l('Entity', 'الجهة'), $l('Orders', 'الطلبات'), $l('Revenue', 'الإيراد')], 'rows' => array_map(fn ($r) => [$r['name'], $r['orders'], $r['revenue']], $s['by_entity'])]],
            ['heading' => $l('Refunds', 'المبالغ المردودة'), 'table' => ['head' => [$l('Credit note', 'إشعار دائن'), $l('Amount', 'المبلغ'), $l('Reason', 'السبب')], 'rows' => array_map(fn ($r) => [$r['credit_note_no'], $r['amount'], $r['reason']], $s['refunds'])]],
        ]];
    }
}
