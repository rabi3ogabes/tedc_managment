<?php

namespace App\Payments;

use App\Models\EntityAccount;
use App\Models\Order;
use App\Models\Refund;
use App\Services\FileStorage;
use App\Services\ThemeService;
use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;

/** Bilingual invoices and credit notes with numbers that run on without gaps (INV-2026-000001), as PDF files in the documents store. */
class InvoiceService
{
    public function __construct(private readonly FileStorage $files, private readonly PaymentSettings $settings) {}

    public function nextNumber(string $prefix): string
    {
        return DB::transaction(function () use ($prefix) {
            $key = $prefix.'-'.now()->year;
            DB::table('invoice_counters')->insertOrIgnore(['key' => $key, 'last' => 0]);
            $row = DB::table('invoice_counters')->where('key', $key)->lockForUpdate()->first();
            $next = ((int) $row->last) + 1;
            DB::table('invoice_counters')->where('key', $key)->update(['last' => $next]);

            return sprintf('%s-%06d', $key, $next);
        });
    }

    public function issue(Order $order): Order
    {
        if ($order->invoice_no) {
            return $order;
        }
        $no = $this->nextNumber('INV');
        $pdf = $this->render($no, false, $order, $order->items, (float) $order->subtotal, (float) $order->discount, (float) $order->vat, (float) $order->total, null, null);
        $path = $this->files->put('documents', 'invoices/'.$no.'.pdf', $pdf, 'application/pdf');
        $order->update(['invoice_no' => $no, 'invoice_pdf_path' => $path]);

        return $order;
    }

    /** @param  array<string, mixed>  $selection  the refunded part: lines (with qty), and the amounts */
    public function creditNote(Refund $refund, Order $order): Refund
    {
        $no = $this->nextNumber('CN');
        $lines = $refund->items ?? [];
        $sub = array_sum(array_map(fn ($l) => (float) ($l['gross'] ?? $l['refund'] ?? 0), $lines));
        $pdf = $this->render($no, true, $order, $lines, $sub, 0.0, 0.0, (float) $refund->amount, $order->invoice_no, $refund->reason);
        $path = $this->files->put('documents', 'invoices/'.$no.'.pdf', $pdf, 'application/pdf');
        $refund->update(['credit_note_no' => $no, 'credit_note_pdf_path' => $path]);

        return $refund;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function render(string $number, bool $credit, Order $order, array $lines, float $sub, float $disc, float $vat, float $total, ?string $against, ?string $note): string
    {
        $s = $this->settings->all();
        $theme = app(ThemeService::class);
        $center = $theme->centerName();
        $entity = $order->entity_account_id ? EntityAccount::find($order->entity_account_id) : null;
        $user = $order->user;
        $html = view('invoices.document', [
            'number' => $number, 'credit' => $credit, 'against' => $against, 'orderNumber' => $order->number, 'date' => now()->toDateString(), 'logo' => $theme->get()['identity']['logo_ar'] ?? null,
            'seller' => ['ar' => $s['seller_name_ar'] ?: (is_array($center) ? ($center['ar'] ?? '') : (string) $center), 'en' => $s['seller_name_en'] ?: (is_array($center) ? ($center['en'] ?? '') : (string) $center), 'tax_id' => $s['tax_id']],
            'buyer' => ['name' => $entity ? $entity->name_en.' · '.$entity->name_ar : ($user?->name ?? ''), 'detail' => $entity ? trim(($entity->cr_number ? 'CR '.$entity->cr_number.' · ' : '').$entity->billing_address) : $user?->email],
            'lines' => array_map(fn ($l) => $l + ['title_ar' => '', 'title_en' => '', 'quantity' => 1, 'unit_price' => (float) ($l['refund'] ?? 0), 'discount' => 0, 'vat' => 0, 'total' => (float) ($l['refund'] ?? 0)], $lines),
            'subtotal' => $sub, 'discount' => $disc, 'vat' => $vat, 'total' => $total, 'currency' => $order->currency, 'note' => $note,
        ])->render();
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 14, 'default_font' => 'dejavusans', 'tempDir' => storage_path('app/mpdf'), 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $mpdf->SetTitle($number);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }
}
