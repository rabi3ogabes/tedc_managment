@php
    $ar = ($lang ?? 'ar') === 'ar';
    $t = fn (string $a, string $e) => $ar ? $a : $e;
@endphp
<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>{{ $t('شاهين - مشروع بوابة التدريب', 'Shaheen – Training Portal Project') }}</title>
<style>
  :root { --maroon: #8a1538; --maroon-soft: #f8f1f3; --line: #f1e3e7; --gold: #a29475; --ink: #2b1d22; --muted: #6b5a60; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--maroon-soft); color: var(--ink);
    font: 15px/1.6 {{ $ar ? '"Tajawal", "Segoe UI", Tahoma' : '"Inter", "Segoe UI", system-ui' }}, sans-serif; }
  main { width: 100%; max-width: 560px; background: #fff; border: 1px solid var(--line); border-radius: 18px; overflow: hidden; box-shadow: 0 18px 40px -24px rgba(138, 21, 56, .35); }
  header { display: flex; align-items: center; gap: 10px; padding: 14px 20px; background: var(--maroon); color: #fff; }
  header b { font-size: 15px; letter-spacing: .2px; }
  header span { margin-inline-start: auto; font-size: 12px; opacity: .8; }
  .mark { width: 28px; height: 28px; border-radius: 8px; background: var(--gold); display: grid; place-items: center; font-weight: 700; color: #fff; font-size: 13px; }
  section { padding: 22px 22px 24px; }
  h1 { margin: 0 0 6px; font-size: 19px; line-height: 1.35; }
  p { margin: 0 0 14px; color: var(--muted); }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 12px; border: 0; background: var(--maroon); color: #fff;
    font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; transition: transform .12s cubic-bezier(.23, 1, .32, 1), box-shadow .12s cubic-bezier(.23, 1, .32, 1); }
  .btn:focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }
  @media (hover: hover) and (pointer: fine) { .btn:hover { box-shadow: 0 8px 18px -10px rgba(138, 21, 56, .6); } }
  .btn:active { transform: scale(.98); }
  @media (prefers-reduced-motion: reduce) { .btn { transition: none; } }
  .note { font-size: 13px; }
  fieldset { border: 1px solid var(--line); border-radius: 14px; padding: 10px 14px 12px; margin: 0 0 12px; }
  legend { padding: 0 6px; font-weight: 600; }
  label { display: flex; gap: 10px; align-items: flex-start; padding: 6px 2px; cursor: pointer; }
  label.lesson { padding-inline-start: 26px; font-size: 14px; color: var(--muted); }
  input[type=checkbox] { width: 18px; height: 18px; margin-top: 3px; accent-color: var(--maroon); flex: none; }
  .list { max-height: 52vh; overflow: auto; margin: 0 -4px 14px; padding: 0 4px; }
  .empty { padding: 14px; border-radius: 12px; background: var(--maroon-soft); }
  .error h1 { color: var(--maroon); }
</style>
</head>
<body>
<main>
  <header><span class="mark" aria-hidden="true">{{ $t('ش', 'S') }}</span><b>{{ $t('شاهين - مشروع بوابة التدريب', 'Shaheen') }}</b><span>{{ $platform ?? '' }}</span></header>
  <section class="{{ $mode === 'error' ? 'error' : '' }}">
    @if ($mode === 'open')
      <h1>{{ $title }}</h1>
      <p>{{ $t('يُفتح المحتوى في نافذة شاهين. إن لم يُفتح تلقائيًا فاضغط الزر.', 'The content opens in a Shaheen window. If it does not open by itself, press the button.') }}</p>
      <a id="go" class="btn" href="{{ $url }}" target="_blank" rel="noopener">{{ $t('افتح في شاهين', 'Open in Shaheen') }}</a>
      <script>if (window.top === window.self) { location.replace(document.getElementById('go').href); }</script>
    @elseif ($mode === 'link')
      <h1>{{ $t('لديك حساب في شاهين بهذا البريد', 'You already have a Shaheen account with this e-mail') }}</h1>
      <p>{{ $t('لحماية حسابك لا نربطه دون إذنك. سجّل الدخول إلى شاهين مرة واحدة لتأكيد الربط، ثم تُفتح الدروس من منصتك مباشرة.', 'To protect your account we never link it without you. Sign in to Shaheen once to confirm, and later launches from your platform open directly.') }}</p>
      <a class="btn" href="{{ $url }}" target="_blank" rel="noopener">{{ $t('سجّل الدخول واربط الحساب', 'Sign in and link my account') }}</a>
    @elseif ($mode === 'signin')
      <h1>{{ $title }}</h1>
      <p>{{ $t('حسابك في شاهين يحمل صلاحيات إدارية، لذا لا يُفتح بالدخول من منصة أخرى. سجّل الدخول بنفسك للمتابعة.', 'Your Shaheen account has staff permissions, so another platform cannot sign it in. Sign in yourself to continue.') }}</p>
      <a class="btn" href="{{ $url }}" target="_blank" rel="noopener">{{ $t('سجّل الدخول إلى شاهين', 'Sign in to Shaheen') }}</a>
    @elseif ($mode === 'select')
      <h1>{{ $t('اختر محتوى شاهين لمقررك', 'Choose Shaheen content for your course') }}</h1>
      <p>{{ $t('يظهر كل اختيار في منصتك رابطًا يفتح الدرس أو البرنامج في شاهين، وتعود الدرجة إلى سجل الدرجات.', 'Each choice appears in your platform as a link that opens the lesson or programme in Shaheen, and the grade comes back to your gradebook.') }}</p>
      <form method="POST" action="{{ $action }}">
        <input type="hidden" name="launch" value="{{ $launch }}">
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="list">
          @forelse ($catalogue as $entry)
            <fieldset>
              <legend>{{ $ar ? ($entry['program']->title_ar ?: $entry['program']->title_en) : ($entry['program']->title_en ?: $entry['program']->title_ar) }}</legend>
              <label><input type="checkbox" name="items[]" value="program:{{ $entry['program']->id }}"><span>{{ $t('البرنامج كاملًا', 'The whole programme') }}</span></label>
              @foreach ($entry['lessons'] as $lesson)
                <label class="lesson"><input type="checkbox" name="items[]" value="lesson:{{ $lesson->id }}"><span>{{ $ar ? ($lesson->title_ar ?: $lesson->title_en) : ($lesson->title_en ?: $lesson->title_ar) }}</span></label>
              @endforeach
            </fieldset>
          @empty
            <div class="empty">{{ $t('لم يُسمح لهذه المنصة بأي برنامج بعد. اطلب من مسؤول شاهين إضافة البرامج في إعدادات منصات LTI.', 'This platform may not reach any programme yet. Ask the Shaheen administrator to add programmes in the LTI platform settings.') }}</div>
          @endforelse
        </div>
        <button class="btn" type="submit">{{ $t('أضف إلى المقرر', 'Add to the course') }}</button>
      </form>
    @elseif ($mode === 'post')
      <h1>{{ $t('جارٍ الإرسال إلى منصتك…', 'Sending to your platform…') }}</h1>
      <form id="back" method="POST" action="{{ $action }}"><input type="hidden" name="JWT" value="{{ $jwt }}"><button class="btn" type="submit">{{ $t('متابعة', 'Continue') }}</button></form>
      <script>document.getElementById('back').submit();</script>
    @else
      <h1>{{ $t('تعذّر فتح المحتوى', 'This content could not be opened') }}</h1>
      <p>{{ $message }}</p>
      <p class="note">{{ $t('ارجع إلى منصتك وافتح الرابط مرة أخرى. إن تكررت المشكلة فأبلغ مسؤول المنصة.', 'Go back to your platform and open the link again. If it happens again, tell your platform administrator.') }}</p>
    @endif
  </section>
</main>
</body>
</html>
