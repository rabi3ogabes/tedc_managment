@php
    $user = $certificate->employee->user;
    $program = $certificate->program;
@endphp
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    body { font-family: dejavusans; color: {{ $primary }}; margin: 0; }
    .frame { position: absolute; top: 10mm; left: 10mm; width: 275mm; height: 188mm; border: 3px solid {{ $primary }}; }
    .inner { position: absolute; top: 14mm; left: 14mm; width: 267.5mm; height: 180.5mm; border: 1px solid {{ $accent }}; }
    .page { padding: 15mm 24mm 0 24mm; text-align: center; }
    .center-name { font-size: 15pt; color: {{ $primary }}; font-weight: bold; }
    .center-name-en { font-size: 10pt; color: #6b7280; letter-spacing: 1px; }
    .title { font-size: 30pt; color: {{ $accent }}; font-weight: bold; margin-top: 6mm; }
    .title-en { font-size: 12pt; color: #6b7280; letter-spacing: 3px; text-transform: uppercase; }
    .lead { font-size: 13pt; margin-top: 5mm; }
    .name { font-size: 26pt; font-weight: bold; margin: 4mm 0; color: {{ $primary }}; }
    .name-en { font-size: 12pt; color: #6b7280; }
    .program { font-size: 18pt; font-weight: bold; color: {{ $primary }}; margin-top: 3mm; }
    .program-en { font-size: 11pt; color: #6b7280; }
    .hours { font-size: 12pt; margin-top: 4mm; }
    .footer { width: 100%; margin-top: 6mm; }
    .footer td { font-size: 9pt; color: #374151; vertical-align: bottom; }
    .gold-line { width: 60mm; border-top: 2px solid {{ $accent }}; margin: 6mm auto 0 auto; }
</style>
</head>
<body>
<div class="frame"></div>
<div class="inner"></div>
<div class="page">
    @if($logo)<div style="margin-bottom: 4mm;"><img src="{{ $logo }}" style="height: 18mm;"></div>@endif
    <div class="center-name">{{ $center['ar'] }}</div>
    <div class="center-name-en">{{ $center['en'] }}</div>

    <div class="title">{{ __('messages.certificate.title', [], 'ar') }}</div>
    <div class="title-en">{{ __('messages.certificate.title', [], 'en') }}</div>
    <div class="gold-line"></div>

    <div class="lead">{{ __('messages.certificate.certify', ['center' => $center['ar']], 'ar') }}</div>
    <div class="name">{{ $user->name_ar ?: $user->name }}</div>
    <div class="name-en">{{ $user->name }}@if($certificate->employee->school) — {{ $certificate->employee->school->name_ar }}@endif</div>

    <div class="lead">{{ __('messages.certificate.completed', [], 'ar') }}</div>
    <div class="program">{{ $program->title_ar }}</div>
    <div class="program-en">{{ $program->title_en }}</div>
    <div class="hours">{{ __('messages.certificate.hours', ['hours' => rtrim(rtrim(number_format($certificate->hours, 1), '0'), '.')], 'ar') }}</div>

<table class="footer">
    <tr>
        <td width="33%" style="text-align: right;">
            <strong>رقم الشهادة:</strong> {{ $certificate->certificate_no }}<br>
            <strong>تاريخ الإصدار:</strong> {{ $certificate->issued_at->format('Y/m/d') }}<br>
            <strong>رمز التحقق:</strong> {{ $certificate->verification_code }}
        </td>
        <td width="34%" style="text-align: center;">
            <div style="border-top: 1px solid {{ $primary }}; width: 55mm; margin: 0 auto; padding-top: 2mm;">مدير المركز<br>Center Director</div>
        </td>
        <td width="33%" style="text-align: left;">
            <img src="{{ $qr }}" style="width: 22mm; height: 22mm;"><br>
            {{ __('messages.certificate.verify', [], 'ar') }}
        </td>
    </tr>
</table>
</div>
</body>
</html>
