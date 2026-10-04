<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<body style="margin:0;background:#f4f1ea;font-family:Tahoma,'Segoe UI',Arial,sans-serif;color:#1c1c1c">
<div style="max-width:560px;margin:28px auto;background:#fff;border-radius:18px;overflow:hidden;border:1px solid #e7e1d3">
    <div style="background:#14213d;color:#fff;padding:22px 28px">
        <div style="font-size:12px;letter-spacing:.4px;color:#d9b45a">{{ $center[$locale] }}</div>
        <div style="margin-top:6px;font-size:20px;font-weight:bold;line-height:1.5">{{ $title }}</div>
    </div>
    <div style="height:4px;background:linear-gradient(90deg,#d9b45a,#f1dca0)"></div>
    <div style="padding:28px;line-height:1.95;font-size:15px">
        @if ($body)
            <p style="margin:0 0 20px">{!! nl2br(e($body)) !!}</p>
        @endif
        <a href="{{ $url }}" style="display:inline-block;background:#14213d;color:#fff;text-decoration:none;padding:12px 26px;border-radius:12px;font-weight:bold">{{ $locale === 'ar' ? 'افتح المنصة' : 'Open the platform' }}</a>
    </div>
    <div style="background:#faf8f3;color:#8a8576;font-size:12px;padding:14px 28px;text-align:center">
        {{ $locale === 'ar' ? 'رسالة تلقائية من '.$center['ar'] : 'Automatic message from '.$center['en'] }}
    </div>
</div>
</body>
</html>
