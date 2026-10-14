# Lusail — the drop-in guide (UX-03)

> العربية أولًا، ثم English. Check at any time with `php artisan tedc:fonts-check` (`--strict` fails when anything is missing).

## العربية

### الوضع الحالي
خط **Lusail** هو الخط المعتمد لدى الوزارة، وهو **مرخَّص ولا يُضمَّن في المستودع**. حتى تُوضع ملفاته يبقى الموقع على قائمة الخطوط الاحتياطية (قطر سانس ← Tajawal)، وتبقى الشهادات والتقارير بخطوط mPDF، ويبقى التطبيق على Tajawal. لا يلزم تعديل أي شيفرة عند وصول الملفات: يكفي وضعها في الأماكن التالية.

### أين تُوضع الملفات

| أين | المجلد | الملفات | الصيغة |
|---|---|---|---|
| الموقع | `web/public/fonts/lusail/` | `Lusail-Regular.woff2` (400) · `Lusail-Medium.woff2` (500) · `Lusail-Bold.woff2` (700) | WOFF2 |
| ملفات PDF (الشهادات، الفواتير، التقارير) | `backend/resources/fonts/` (أو `TEDC_FONTS_PDF_DIR`) | `Lusail-Regular.ttf` و`Lusail-Bold.ttf` (إن لم يوجد الغامق حلّ العادي محلّه) | TTF |
| التطبيق | `mobile/assets/fonts/` | `Lusail-Regular.ttf` · `Lusail-Medium.ttf` · `Lusail-Bold.ttf` | TTF |

### بعد النسخ
1. `php artisan tedc:fonts-check` يتحقق من الملفات (الصيغة والحجم) ويذكر الناقص.
2. للتطبيق: `php artisan tedc:fonts-check --register-app` يعلن العائلة في `mobile/pubspec.yaml` (لا يفعل ذلك إلا حين تكون الملفات الثلاثة موجودة، وإلا فسدت عملية البناء)، ثم `flutter pub get`.
3. للموقع: لا خطوة إضافية؛ يتحقق الموقع مرة واحدة في كل جلسة متصفح من وجود الملف ثم يسجّله.
4. للمطبوعات: تُستخدم Lusail تلقائيًا في كل المستندات (مع بقاء خطوط mPDF خلفها لأي حرف ناقص).
5. اعتماد ارتفاع الأسطر: شغّل لقطات المقارنة `cd web && npm run e2e -- fonts-visual` (تحتاج خوادم التطوير المحلية)؛ تُحفظ في `web/test-results/fonts/` لقطةٌ بالخط الاحتياطي وأخرى بـLusail لثلاث صفحات، مع قياسات ارتفاع الأسطر، وتُفشل الفحص إن قُصّ نص.

### الترخيص والمستودع
- لا تُودِع الملفات المرخَّصة في مستودع عام. تتجاهلها `.gitignore` عمدًا (`*.woff2` في مجلد Lusail، و`resources/fonts/*.ttf`، و`Lusail-*.ttf` في التطبيق).
- في نسخة خاصة من المستودع تضيفها `git add -f`، أو تُركَّب على الخادم أو داخل صورة النشر (`TEDC_FONTS_PDF_DIR` لملفات PDF).
- ملفات الويب تُخدَّم للعموم بطبيعتها؛ تأكد أن الترخيص يسمح بخط ويب.

### التراجع
احذف الملفات (أو أزل العائلة من `pubspec.yaml`)؛ يعود كل شيء إلى الخطوط الاحتياطية دون أي تعديل آخر.

## English

### Where things stand
**Lusail** is the Ministry's approved typeface. It is **licensed and not bundled in the repository**. Until its files are placed, the site keeps its fallback stack (Qatar Sans → Tajawal), certificates and reports keep mPDF's fonts, and the app keeps Tajawal. No code changes when the files arrive: placing them is enough.

### Where the files go

| Where | Folder | Files | Format |
|---|---|---|---|
| The site | `web/public/fonts/lusail/` | `Lusail-Regular.woff2` (400) · `Lusail-Medium.woff2` (500) · `Lusail-Bold.woff2` (700) | WOFF2 |
| PDFs (certificates, invoices, reports) | `backend/resources/fonts/` (or `TEDC_FONTS_PDF_DIR`) | `Lusail-Regular.ttf` and `Lusail-Bold.ttf` (the regular one stands in when there is no bold) | TTF |
| The app | `mobile/assets/fonts/` | `Lusail-Regular.ttf` · `Lusail-Medium.ttf` · `Lusail-Bold.ttf` | TTF |

### After copying
1. `php artisan tedc:fonts-check` checks the files (format and size) and lists what is missing.
2. For the app: `php artisan tedc:fonts-check --register-app` declares the family in `mobile/pubspec.yaml` (only when all three files are there, otherwise the build would fail), then `flutter pub get`.
3. For the site there is nothing more: once per browser session it checks that the file exists and registers it.
4. For printed documents Lusail is used automatically everywhere (mPDF's fonts stay behind it for any missing glyph).
5. Approving line heights: run the comparison screenshots with `cd web && npm run e2e -- fonts-visual` (needs the local dev servers). For three pages it saves one screenshot with the fallback font and one with Lusail in `web/test-results/fonts/`, with the line-height measurements, and fails if any text is clipped.

### Licence and the repository
- Do not commit the licensed files to a public repository. `.gitignore` ignores them on purpose (`*.woff2` in the Lusail folder, `resources/fonts/*.ttf`, `Lusail-*.ttf` in the app).
- In a private copy of the repository add them with `git add -f`, or mount them on the server or in the deployment image (`TEDC_FONTS_PDF_DIR` for PDFs).
- Web fonts are served to everyone by nature; make sure the licence allows web use.

### Rolling back
Delete the files (or remove the family from `pubspec.yaml`); everything returns to the fallback fonts with no other change.
