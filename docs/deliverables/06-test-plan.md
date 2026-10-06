# خطة الاختبار وتقاريره

## الاستراتيجية
| المستوى | الأداة | البيئة | معيار الدخول | معيار الخروج |
|---|---|---|---|---|
| الوحدة والميزة | PHPUnit (SQLite + PostgreSQL) | CI | شيفرة مدموجة | 0 فشل |
| الواجهة | ESLint + البناء | CI | — | 0 أخطاء |
| الجوال | `flutter analyze` + اختبارات | CI | — | 0 مشكلات |
| التكامل | محاكيات (Azurite، بوابة تجريبية، Teams تجريبي) | CI/تجهيز | — | اجتياز |
| القبول (UAT) | سيناريوهات لكل دور | تجهيز | Beta | اعتماد أصحاب الأدوار |
| الأداء | k6 (`perf/`) | تجهيز | Beta | p95 < 1.5 ثانية، أخطاء < 0.1% |
| الأمن | SAST/DAST/تبعيات/حاويات + VAPT | CI/تجهيز | Beta | لا ثغرات عالية/حرجة |
البيانات: بذور تركيبية فقط؛ لا بيانات إنتاج. التتبّع: لكل متطلب معرّف في سجل الفجوات وملف `traceability.generated.md`.

## التقارير
`test-report.generated.md` يُنتج من نتيجة CI (عدد الاختبارات والتأكيدات وفق الوحدات). سيناريوهات UAT لكل دور في `uat/` بقالب (خطوات، نتيجة متوقعة، فعلية، حالة، ملاحظات). تقارير الأداء والأمن من المرحلة 17.

## متتبع الأخطاء
قالب GitHub Issues/Projects: الحقول (الخطورة، الأولوية، الوحدة، البيئة، خطوات التكرار، المسؤول، الحالة) ومطابقة مستويات الخدمة: P1 حرج (استجابة 15 دقيقة، حل ساعتان)، P2 عالٍ (30 دقيقة/4 ساعات)، P3 متوسط (ساعتان/يوم عمل)، P4 منخفض (4 ساعات/يومان).

---

# Test plan and reports (English copy)
Levels: unit/feature (PHPUnit on SQLite and PostgreSQL), web (ESLint + build), mobile (analyze + tests), integration (emulators/mocks), UAT per role on staging, performance (k6), security (SAST/DAST/dependency/container scans + VAPT). Entry/exit criteria as in the table above; synthetic data only; traceability from every requirement ID to evidence and tests (`traceability.generated.md`). Reports are generated from CI results; UAT scripts per role use a template (steps, expected, actual, status, notes). Bug tracker: GitHub Issues/Projects with severity and priority aligned to the SLA matrix (P1–P4).
