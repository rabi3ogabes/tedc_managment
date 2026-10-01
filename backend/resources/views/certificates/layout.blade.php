@php
    $maroon = '#7b1e3a';
    $signers = config('tedc.certificates.signers', []);
@endphp
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    body { font-family: xbriyaz, dejavusans; color: #2b2b35; margin: 0; }
    .frame { position: absolute; top: 9mm; left: 9mm; width: 277mm; height: 190mm; border: 0.6mm solid {{ $maroon }}; }
    .head { width: 273mm; margin: 14mm 12mm 0 12mm; }
    .head td { vertical-align: top; }
    .center-ar { font-size: 12pt; font-weight: bold; color: {{ $maroon }}; }
    .center-en { font-size: 9pt; color: #5b6070; }
    .orn { text-align: center; color: {{ $maroon }}; font-size: 11pt; letter-spacing: 3mm; margin: 0; }
    .page { padding: 18mm 28mm 0 28mm; text-align: center; }
    .title { font-size: 40pt; font-weight: bold; color: #8a6d3b; margin: 4mm 0 2mm 0; }
    .line { font-size: 13pt; color: #3a3a46; line-height: 1.75; }
    .name { font-size: 22pt; font-weight: bold; color: {{ $maroon }}; margin: 2mm 0 0 0; }
    .school { font-size: 14pt; color: #34568b; margin-top: 1mm; }
    .program { font-size: 17pt; color: #2f8a64; margin: 1mm 0; }
    .foot-wrap { position: absolute; left: 28mm; top: 158mm; width: 241mm; }
    .foot { width: 241mm; }
    .gem { font-family: dejavusans; }
    .foot td { text-align: center; vertical-align: top; font-size: 11pt; }
    .sig-name { font-weight: bold; color: {{ $maroon }}; border-top: 0.3mm solid #b9b9c3; padding-top: 1.5mm; }
    .sig-title { color: #5b6070; font-size: 10pt; }
    .meta { font-size: 8pt; color: #8a8a96; }
</style>
</head>
<body>
<div class="frame"></div>
<div style="position: absolute; top: 17mm; right: 18mm; width: 90mm; text-align: right;">
    @if($logo)<img src="{{ $logo }}" style="height: 12mm;"><br>@endif
    <span class="center-ar">{{ $center['ar'] }}</span><br><span class="center-en" dir="ltr">{{ $center['en'] }}</span>
</div>
<p class="orn" style="position: absolute; top: 12mm; left: 0; width: 297mm;"><span class="gem">&#9670; &#9671; &#9670;</span></p>
<div class="page">
    @yield('body')
</div>
<div class="foot-wrap"><table class="foot">
    <tr>
        <td width="33%">
            @isset($signers[1])<div style="height: 12mm;"></div><div class="sig-name">{{ $signers[1]['name_ar'] }}</div><div class="sig-title">{{ $signers[1]['title_ar'] }}</div>@endisset
        </td>
        <td width="34%">
            <img src="{{ $qr }}" style="width: 20mm; height: 20mm;"><br>
            <span class="meta">{{ __('messages.certificate.verify', [], 'ar') }}</span><br>
            <span class="meta" dir="ltr">{{ $number }} · {{ $code }}</span><br>
            <span class="meta">تاريخ الإصدار {{ $issued->format('Y-m-d') }}</span>
        </td>
        <td width="33%">
            @isset($signers[0])<div style="height: 12mm;"></div><div class="sig-name">{{ $signers[0]['name_ar'] }}</div><div class="sig-title">{{ $signers[0]['title_ar'] }}</div>@endisset
        </td>
    </tr>
</table></div>
<p class="orn" style="position: absolute; top: 193mm; left: 0; width: 297mm;"><span class="gem">&#9670; &#9671; &#9670;</span></p>
</body>
</html>
