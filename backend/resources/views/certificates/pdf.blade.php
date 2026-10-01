@extends('certificates.layout', ['number' => $certificate->certificate_no, 'code' => $certificate->verification_code, 'issued' => $certificate->issued_at])
@php
    $user = $certificate->employee->user;
    $program = $certificate->program;
    $hours = rtrim(rtrim(number_format($certificate->hours, 1), '0'), '.');
@endphp
@section('body')
    <div class="title">شهادة إتمام برنامج تدريبي</div>
    <div class="line">يسر {{ $center['ar'] }} أن يشهد بأن السيد/ة:</div>
    <div class="name">{{ $user->name_ar ?: $user->name }}</div>
    @if($certificate->employee->school)<div class="school">{{ $certificate->employee->school->name_ar }}</div>@endif
    <div class="line" style="margin-top: 3mm;">قد أتمّ/ت بنجاح حضور برنامج:</div>
    <div class="program">{{ $program->title_ar }}</div>
    <div class="line">بمعدل ( {{ $hours }} ) ساعات تدريبية، وذلك ضمن جهود المركز في بناء القدرات وتنمية الكوادر التعليمية والارتقاء بالممارسات المهنية.<br>مع خالص التمنيات بدوام التميز والريادة.</div>
@endsection
