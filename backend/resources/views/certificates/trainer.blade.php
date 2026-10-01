@extends('certificates.layout', ['number' => $certificate->certificate_no, 'code' => $certificate->verification_code, 'issued' => $certificate->issued_at])
@php
    $trainer = $certificate->trainer;
    $program = $certificate->program;
    $hours = rtrim(rtrim(number_format($certificate->hours, 1), '0'), '.');
    $name = trim(($trainer->title_ar ? $trainer->title_ar.' ' : '').($trainer->name_ar ?: $trainer->name_en));
@endphp
@section('body')
    <div class="title">شكر وتقدير</div>
    <div class="line">يسر {{ $center['ar'] }} أن يتقدم بالشكر والتقدير للسيد/ة:</div>
    <div class="name">{{ $name }}</div>
    @if($trainer->school)<div class="school">{{ $trainer->school->name_ar }}</div>@elseif($trainer->organization)<div class="school">{{ $trainer->organization }}</div>@endif
    <div class="line" style="margin-top: 3mm;">وذلك لمشاركته/ا الفاعلة وتعاونه/ا المثمر في تدريب برنامج:</div>
    <div class="program">{{ $program->title_ar }}</div>
    <div class="line">والذي تم تنفيذه بمعدل ( {{ $hours }} ) ساعات تدريبية أسهمت في بناء الاتجاهات التربوية وتنمية قدرات الكوادر التعليمية والارتقاء بالممارسات المهنية.<br>مع خالص الدعوات لكم بدوام التميز والريادة.</div>
@endsection
