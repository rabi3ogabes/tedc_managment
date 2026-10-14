# Ministry systems and providers — how they connect to TEDC

> العربية أولًا، ثم English. The per-system guides (`hr.md`, `licences.md`, `nsis.md`, `qneds.md`, `saaed.md`, `sijil.md`, `ministry-site.md`, `sms-gateway.md`, `moe-epay.md`) are generated from the shipped contracts by `php artisan tedc:integration-docs`; the others are written by hand.

## العربية

### الفكرة
كل نظام خارجي تذكره كراسة الشروط يصبح **مهمة إعداد لا مهمة برمجة** يوم تمنح الوزارة الصلاحية. لكل نظام:

1. **عقد (contract)**: العمليات (المسار والطريقة)، المصادقة، مخططا الطلب والاستجابة، نموذج الأخطاء، حالات الاختبار، وقائمة الإطلاق. بيانات فقط، بلغة قوالب آمنة لا تقرأ سرًّا ولا تغيّر العنوان ولا تنفّذ شيفرة.
2. **مطابقة (mapping)**: كيف تتحول حقول النظام إلى حقول TEDC، مع تحويلات من قائمة ثابتة (تواريخ، جداول رموز، أسماء عربية/إنجليزية، مبالغ بالفلس…).
3. **محرّك عام** ينفّذ العملية من العقد: OAuth2 / مفتاح API / Basic / mTLS / توقيع HMAC أو RSA؛ ترقيم الصفحات؛ مفاتيح عدم التكرار؛ حد المعدل؛ حجب الأسرار من كل رسالة.
4. **خادم تجريبي** `php artisan tedc:mock-ministry {system} --port=` يجيب من الأمثلة المسجّلة (مع الأخطاء والبطء) للتجارب والعروض. في الاختبارات تحلّ محلّه إجابات `Http::fake` من الأمثلة نفسها.
5. **اختبارات عقدية** وقائمة إطلاق مع **من ومتى** لكل خطوة.

### ماذا يفعل المسؤول يوم الإطلاق
1. تعبئة الإعدادات في بطاقة التكامل (العنوان، المفتاح…) والضغط على «فحص الاتصال».
2. فتح تبويب **العقد**: مقارنة المرفق بوثيقة الوزارة وتعديل المسارات والحقول. كل حفظ **نسخة** مدققة، ويمكن عرض الفروق والرجوع إلى أي نسخة.
3. تبويب **المطابقة**: تجربة المطابقة على إجابة مسجّلة قبل الحفظ.
4. تبويب **حالات الاختبار**: تشغيلها على الإجابات المسجّلة، ثم على النظام الفعلي (عمليات القراءة فقط؛ تُعرض الأعداد والفجوات لا الأشخاص).
5. تبويب **قائمة الإطلاق**: تسجيل ما تحقق، ومن حقّقه ومتى.

### الأنظمة
| النظام | الدليل | ما ينتظره |
|---|---|---|
| الموارد البشرية / موارد | [hr.md](hr.md) | مواصفات الواجهة، بيانات الدخول، واجهة طلبات التعديل |
| الرخص المهنية | [licences.md](licences.md) | مواصفات الواجهة وبيانات الدخول |
| NSIS | [nsis.md](nsis.md) | مواصفات الواجهة وبيانات الدخول |
| QNEDS | [qneds.md](qneds.md) | مواصفات الواجهة وبيانات الدخول |
| سعيد | [saaed.md](saaed.md) | مواصفات الواجهة وبيانات الدخول |
| سجل | [sijil.md](sijil.md) | مواصفات الواجهة وبيانات الدخول |
| موقع الوزارة | [ministry-site.md](ministry-site.md) | عنوان الاستقبال ومفتاحه |
| الرسائل النصية (هدهد) | [sms-gateway.md](sms-gateway.md) | وثيقة الواجهة وبيانات الدخول |
| بوابة الدفع | [moe-epay.md](moe-epay.md)، [moe-epay-certification.md](moe-epay-certification.md) | المواصفات الرسمية وبيئة تجريبية |
| أجهزة البصمة | [attendance-connector.md](attendance-connector.md) | موديلات الأجهزة وزيارة ميدانية |
| المكتبات (مكتبتي، مكتبة قطر الوطنية) | [libraries.md](libraries.md) | واجهات رسمية موثّقة أو اتفاقية |
| منصات المحتوى | [providers.md](providers.md) | بيانات كل مزوّد وعقده |

## English

### The idea
Every outside system the RFP names becomes a **configuration task, not a coding task** on the day the Ministry gives access. Each system has:

1. A **contract**: the operations (path and method), authentication, request and response schemas, error model, test cases and the go-live checklist. Plain data in a safe placeholder language that cannot read a secret, change the host or run code.
2. A **mapping**: how the system's fields become TEDC's, with transforms from a fixed list (dates, code tables, Arabic/English names, amounts in fils…).
3. A **generic driver** that carries an operation out from the contract: OAuth2 / API key / basic / mTLS / HMAC or RSA signature; pagination; idempotency keys; rate limit; secrets scrubbed from every message.
4. A **local mock** `php artisan tedc:mock-ministry {system} --port=` that answers from the recorded examples (errors and slow answers included) for UAT and demos. In tests the same examples answer through `Http::fake`.
5. **Contract tests** and a go-live checklist that records **who and when** for each step.

### What an administrator does on the day
1. Fill in the settings on the integration card (address, key…) and press “Check connection”.
2. Open the **Contract** tab: compare what ships with the Ministry's document and edit paths and fields. Every save is an audited **version**; differences can be viewed and any version restored.
3. **Mapping**: try the mapping on a recorded answer before saving it.
4. **Test cases**: run them on the recorded answers, then on the real system (read-only operations; counts and gaps are shown, never people).
5. **Go-live checklist**: tick what was confirmed, by whom and when.

### The systems
See the table above. Each guide lists what the Ministry provides, what to fill in, the test cases with expected results, and the rollback plan.

### Rules the kit keeps
- **Secrets** never appear in logs, the sandbox, an export or an error message.
- **Templates are data**: no code, no calls, no secrets; the host is fixed to the system's own address setting.
- **Idempotency**: one key per logical operation, the same on every retry; inbound messages are numbered so a repeat is harmless.
- **Arabic survives every hop**: UTF-8 in JSON, UCS-2 where a gateway needs it, a byte-order mark in CSV files.
