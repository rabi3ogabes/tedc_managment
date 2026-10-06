<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><title>{{ $number }}</title>
<style>
  body { font-family: dejavusans, sans-serif; color: #1c2434; font-size: 11pt; }
  h1 { font-size: 20pt; margin: 0 0 4px; color: #3b0b1f; }
  .muted { color: #6b7280; font-size: 9pt; }
  table { width: 100%; border-collapse: collapse; margin-top: 14px; }
  th { background: #3b0b1f; color: #fff; padding: 7px 8px; font-size: 9.5pt; text-align: start; }
  td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  .num { text-align: end; white-space: nowrap; }
  .tot td { border: 0; padding: 3px 8px; }
  .grand td { font-weight: bold; font-size: 12pt; border-top: 2px solid #3b0b1f; }
  .box { border: 1px solid #e5e7eb; padding: 10px 12px; border-radius: 6px; }
  .credit { color: #b91c1c; }
</style></head>
<body>
<table style="margin-top:0"><tr>
  <td style="border:0;width:60%">
    <h1 class="{{ $credit ? 'credit' : '' }}">{{ $credit ? 'إشعار دائن · Credit note' : 'فاتورة ضريبية · Invoice' }}</h1>
    <div><b>{{ $number }}</b></div>
    <div class="muted">{{ $date }} @if($orderNumber) · {{ $orderNumber }} @endif</div>
    @if($credit && $against)<div class="muted">يخص الفاتورة · Against invoice {{ $against }}</div>@endif
  </td>
  <td style="border:0;text-align:end">
    @if($logo)<img src="{{ $logo }}" style="max-height:60px"><br>@endif
    <b>{{ $seller['ar'] }}</b><br>{{ $seller['en'] }}<br>@if($seller['tax_id'])<span class="muted">الرقم الضريبي · Tax ID {{ $seller['tax_id'] }}</span>@endif
  </td></tr></table>

<div class="box" style="margin-top:14px"><div class="muted">إلى · Billed to</div><b>{{ $buyer['name'] }}</b>@if($buyer['detail'])<br><span class="muted">{{ $buyer['detail'] }}</span>@endif</div>

<table>
  <thead><tr><th>البند · Item</th><th class="num">الكمية · Qty</th><th class="num">السعر · Price</th><th class="num">الخصم · Discount</th><th class="num">الضريبة · VAT</th><th class="num">الإجمالي · Total</th></tr></thead>
  <tbody>
  @foreach($lines as $l)
    <tr><td>{{ $l['title_ar'] }}<br><span class="muted">{{ $l['title_en'] }}</span></td><td class="num">{{ $l['quantity'] }}</td><td class="num">{{ number_format($l['unit_price'], 2) }}</td><td class="num">{{ number_format($l['discount'] ?? 0, 2) }}</td><td class="num">{{ number_format($l['vat'] ?? 0, 2) }}</td><td class="num">{{ number_format($l['total'] ?? 0, 2) }}</td></tr>
  @endforeach
  </tbody>
</table>
<table class="tot" style="width:46%;margin-inline-start:auto">
  <tr><td>المجموع · Subtotal</td><td class="num">{{ number_format($subtotal, 2) }}</td></tr>
  <tr><td>الخصم · Discount</td><td class="num">- {{ number_format($discount, 2) }}</td></tr>
  <tr><td>الضريبة · VAT</td><td class="num">{{ number_format($vat, 2) }}</td></tr>
  <tr class="grand"><td>{{ $credit ? 'المبلغ المردود · Credited' : 'الإجمالي · Total' }}</td><td class="num">{{ number_format($total, 2) }} {{ $currency }}</td></tr>
</table>
@if($note)<p class="muted" style="margin-top:18px">{{ $note }}</p>@endif
</body></html>
