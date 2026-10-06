# تصميم تجربة المستخدم والبنية

## نظام التصميم
- **الألوان:** كستنائي عميق (navy-950/900) مع ذهبي (gold-500) وعاجي للخلفية؛ رموز ألوان معرّفة في `web/src/index.css` وتُدار من «الهوية البصرية».
- **الخطوط:** خط لوسيل (يُرفع من الإعدادات؛ بديل افتراضي عند غيابه)، أحجام متدرجة وارتفاع سطر 1.65 للعربية.
- **المكوّنات:** أزرار (primary/gold/outline/ghost/danger)، بطاقات، جداول، نوافذ، تبويبات، شارات حالة، شريط تقدم، رسوم. كلها تدعم RTL وLTR.
- **قوالب الصفحات:** رأس صفحة مع إجراءات، تبويبات، شبكة بطاقات، جدول مع فلاتر، نموذج بقسمين.
- **إمكانية الوصول:** تباين لوني كافٍ، تركيز مرئي بلوحة المفاتيح، `aria-label` للأزرار الأيقونية، احترام تقليل الحركة، أحجام لمس 44px على الجوال.
- **المبادئ:** وضوح قبل الزخرفة، اللغة تتبع المستخدم، الخطأ يشرح الحل، الحالة الفارغة تدعو إلى إجراء.

## البنية
انظر `docs/architecture/HLD.md` و`LLD.md` و`BOM-and-sizing.md`؛ مخطط السياق والتدفقات بصيغة Mermaid.

---

# UX design and architecture (English copy)
**Design system:** deep maroon-navy with gold on ivory (tokens in `web/src/index.css`, editable in Brand Studio); Lusail font uploaded in settings with a safe fallback; component set (buttons, cards, tables, modals, tabs, status badges, progress, charts) with full RTL/LTR; page templates (header + actions, tabs, card grid, filtered table, two-section form); accessibility (contrast, visible keyboard focus, aria labels, reduced motion, 44 px touch targets). Screenshots are taken from the staging environment at sign-off. **Architecture:** see `docs/architecture/HLD.md`, `LLD.md`, `BOM-and-sizing.md`.
