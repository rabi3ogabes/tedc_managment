@php
    // The centre's support contacts (Settings → Help & support). Nothing is printed until the centre has entered a phone or an e-mail.
    $support = app(\App\Services\SupportSettings::class)->all();
    $lang = $locale ?? 'ar';
    $contacts = array_values(array_filter([$support['phone'] ?? '', $support['email'] ?? '']));
@endphp
@if ($contacts !== [])
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #eee3c8;line-height:1.8">
        @if ($lang === 'both')
            <div>تحتاج مساعدة؟ · Need help?</div>
            <div dir="ltr">{{ implode(' · ', $contacts) }}</div>
            @if (filled($support['hours_ar'] ?? '') || filled($support['hours_en'] ?? ''))
                <div>{{ $support['hours_ar'] }} · <span dir="ltr">{{ $support['hours_en'] }}</span></div>
            @endif
        @else
            <div><strong>{{ $lang === 'ar' ? 'تحتاج مساعدة؟' : 'Need help?' }}</strong></div>
            <div dir="ltr" style="text-align:{{ $lang === 'ar' ? 'right' : 'left' }}">{{ implode(' · ', $contacts) }}</div>
            @if (filled($support['hours_'.$lang] ?? ''))
                <div>{{ $support['hours_'.$lang] }}</div>
            @endif
        @endif
    </div>
@endif
