# خطة التشغيل، التسليم النهائي، شهادة الجودة، واتفاقية مستوى الخدمة

## خطة التشغيل (Go-live)
1. **قبل التشغيل (T-14 يومًا):** تجميد الميزات، اختبار استعادة ناجح، اختبار أحمال، VAPT مجتاز، تأكيد الأعلام.
2. **التهجير (T-7):** بروفة نقل البيانات بأداة الترحيل (المرحلة 13) على نسخة من البيانات المقنَّعة؛ مقارنة الأعداد؛ توقيع المركز.
3. **ليلة التشغيل:** إيقاف إدخال البيانات في النظام القديم ← تهجير نهائي ← تحقق ← تحويل DNS ← اختبار دخان بحسابات الأدوار ← فتح النظام.
4. **التراجع:** إن فشل التحقق خلال 4 ساعات يُعاد التحويل إلى النظام القديم والاستعادة من لقطة ما قبل التهجير.
5. **الرعاية المكثفة (30 يومًا):** فريق مناوب، مراجعة يومية للتذاكر والأخطاء، تقرير أسبوعي.

## التسليم النهائي
الشيفرة المصدرية، البنية كشيفرة (`infra/`)، تسليم الأسرار عبر Key Vault بإجراء موثّق ومزدوج التوقيع، فهرس التوثيق (`docs/`)، سجل جلسات نقل المعرفة (تاريخ، موضوع، حضور)، أدلة التشغيل (`docs/ops/`).

## شهادة الجودة (قالب)
«نشهد بأن المنصة اجتازت اختبارات القبول والأداء والأمن وفق خطة الاختبار، وأن المخرجات المبينة في الملحق مطابقة لكراسة الشروط باستثناء البنود المبيّنة.» التوقيعات: مسؤول الجودة · مدير المشروع · ممثل المركز · التاريخ.

## اتفاقية مستوى الخدمة (SLA)
| الأولوية | الاستجابة | الحل | الأمثلة |
|---|---|---|---|
| P1 حرج | 15 دقيقة | ساعتان | توقف النظام، فقدان بيانات، ثغرة نشطة |
| P2 عالٍ | 30 دقيقة | 4 ساعات | وحدة رئيسية معطلة، تعطل تكامل |
| P3 متوسط | ساعتان | يوم عمل | عطل جزئي مع بديل |
| P4 منخفض | 4 ساعات | يومان | استفسار، تحسين |
**مستهدفات الالتزام:** 100% / 98% / 95% / 90% للأولويات P1–P4 على التوالي. **التوافر:** 99.9%. **التصعيد:** L1 مكتب الخدمة ← L2 مهندسو المنصة ← مدير المشروع ← الرئيس التنفيذي (انظر `docs/ops/bcp.md`). **غرامة التأخير:** نسبة من الرسوم الشهرية = (الحالات المتأخرة ÷ الإجمالي) × معامل الغرامة المتفق عليه ⚑ (تُحدَّد مع الجهة). **التقارير:** شهري (الحالات، الالتزام، التوافر، الأداء، الأمن) ومراجعة ربع سنوية.

---

# Go-live, handover, QA certificate and SLA (English copy)
**Go-live:** T-14 freeze, restore test, load test, VAPT passed, flags confirmed; T-7 migration rehearsal on masked data with the migration toolkit and record counts signed by the centre; cut-over night — freeze legacy entry, final migration, verification, DNS switch, smoke test per role, open; rollback within 4 hours to the legacy system and the pre-migration snapshot; 30-day hyper-care. **Handover:** source code, infrastructure code, secrets handed over through Key Vault with a documented two-person procedure, documentation index, knowledge-transfer log, operations runbooks. **QA certificate:** template attesting acceptance, performance and security results against the test plan. **SLA:** P1 respond 15 min / resolve 2 h; P2 30 min / 4 h; P3 2 h / 1 business day; P4 4 h / 2 business days; compliance targets 100/98/95/90 %; availability 99.9 %; escalation L1 → L2 → PM → CEO; penalty = share of the monthly fee proportional to late cases × agreed factor ⚑; monthly report and quarterly review.

---

## الإصدارات والاعتماد · Versions and approval

| الإصدار · Version | التاريخ · Date | المؤلف · Author | الحالة · Status |
|---|---|---|---|
| 0.1 | 2026-10 | فريق التنفيذ · Delivery team | مسودة للمراجعة · Draft for review |
| 1.0 | | | بعد مراجعة المركز · After the centre's review |

| الدور · Role | الاسم · Name | التوقيع · Signature | التاريخ · Date |
|---|---|---|---|
| ممثل المركز · Centre representative | | | |
| ممثل المورّد · Supplier representative | | | |
