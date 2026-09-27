@php
    $user = $certificate->employee->user;
    $program = $certificate->program;
@endphp
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    @page { size: A4-L; margin: 0; }
    body { font-family: dejavusans; color: #0b1f3a; margin: 0; }
    .frame { position: absolute; top: 10mm; left: 10mm; right: 10mm; bottom: 10mm; border: 3px solid #0b1f3a; }
    .inner { position: absolute; top: 14mm; left: 14mm; right: 14mm; bottom: 14mm; border: 1px solid #c8a24a; }
    .page { padding: 26mm 30mm 0 30mm; text-align: center; }
    .center-name { font-size: 15pt; color: #0b1f3a; font-weight: bold; }
    .center-name-en { font-size: 10pt; color: #6b7280; letter-spacing: 1px; }
    .title { font-size: 30pt; color: #c8a24a; font-weight: bold; margin-top: 10mm; }
    .title-en { font-size: 12pt; color: #6b7280; letter-spacing: 3px; text-transform: uppercase; }
    .lead { font-size: 13pt; margin-top: 8mm; }
    .name { font-size: 26pt; font-weight: bold; margin: 4mm 0; color: #0b1f3a; }
    .name-en { font-size: 12pt; color: #6b7280; }
    .program { font-size: 18pt; font-weight: bold; color: #0b1f3a; margin-top: 3mm; }
    .program-en { font-size: 11pt; color: #6b7280; }
    .hours { font-size: 12pt; margin-top: 4mm; }
    .footer { position: absolute; bottom: 22mm; left: 30mm; right: 30mm; }
    .footer td { font-size: 9pt; color: #374151; vertical-align: bottom; }
    .gold-line { width: 60mm; border-top: 2px solid #c8a24a; margin: 6mm auto 0 auto; }
</style>
</head>
<body>
<div class="frame"></div>
<div class="inner"></div>
<div class="page">
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
</div>

<table class="footer" width="100%">
    <tr>
        <td width="33%" style="text-align: right;">
            <strong>رقم الشهادة:</strong> {{ $certificate->certificate_no }}<br>
            <strong>تاريخ الإصدار:</strong> {{ $certificate->issued_at->format('Y/m/d') }}<br>
            <strong>رمز التحقق:</strong> {{ $certificate->verification_code }}
        </td>
        <td width="34%" style="text-align: center;">
            <div style="border-top: 1px solid #0b1f3a; width: 55mm; margin: 0 auto; padding-top: 2mm;">مدير المركز<br>Center Director</div>
        </td>
        <td width="33%" style="text-align: left;">
            <img src="{{ $qr }}" style="width: 28mm; height: 28mm;"><br>
            {{ __('messages.certificate.verify', [], 'ar') }}
        </td>
    </tr>
</table>
</body>
</html>
