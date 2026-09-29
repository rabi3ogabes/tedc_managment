<!doctype html>
<html lang="ar" dir="rtl">
<body style="margin:0;background:#f4f1ea;font-family:Tahoma,Arial,sans-serif;color:#1c1c1c">
<div style="max-width:560px;margin:24px auto;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e7e1d3">
    <div style="background:#8A1538;color:#fff;padding:22px 26px;font-size:18px;font-weight:bold">{{ $center['ar'] }}</div>
    <div style="padding:26px;line-height:1.9;font-size:15px">
        <p style="margin:0 0 12px">عزيزي/عزيزتي {{ $name_ar }}،</p>
        <p style="margin:0 0 12px">تهانينا! نرفق لكم شهادة إتمام برنامج <strong>«{{ $program->title_ar }}»</strong> ({{ $certificate->hours }} ساعة).</p>
        <p style="margin:0 0 18px">يمكنكم التحقق من صحة الشهادة عبر <a href="{{ $verifyUrl }}" style="color:#8A1538">رابط التحقق</a> أو من خلال رمز الاستجابة السريعة على الشهادة.</p>
        <hr style="border:none;border-top:1px solid #eee;margin:20px 0">
        <div dir="ltr" style="text-align:left;color:#444">
            <p style="margin:0 0 12px">Dear {{ $name_en }},</p>
            <p style="margin:0 0 12px">Congratulations! Your certificate for <strong>“{{ $program->title_en }}”</strong> ({{ $certificate->hours }} hours) is attached.</p>
            <p style="margin:0">You can verify it with this <a href="{{ $verifyUrl }}" style="color:#8A1538">verification link</a>.</p>
        </div>
    </div>
    <div style="background:#faf8f3;color:#888;font-size:12px;padding:14px 26px;text-align:center">{{ $center['en'] }} · {{ $certificate->certificate_no }}</div>
</div>
</body>
</html>
