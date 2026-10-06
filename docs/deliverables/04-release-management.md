# إدارة الإصدارات — Release management

## بوابات الإصدار
| الإصدار | النطاق | معايير الخروج | الاعتماد |
|---|---|---|---|
| **Alpha** | الوحدات الأساسية (الأدوار، الهيكل، الاحتياجات، التسجيل، الحضور، الاختبارات، الشهادات) على بيئة التجهيز | اجتياز حزمة الاختبارات الآلية؛ لا أخطاء حرجة؛ عرض للمركز | مدير المشروع + ممثل المركز |
| **Beta** | كل الوحدات + التكاملات والأمن والمدفوعات (مفعّلة بأعلام) | اختبار قبول المستخدمين UAT لكل دور؛ نتائج الأمن والأداء؛ لا أخطاء عالية | لجنة الاستلام |
| **Final** | الإصدار الإنتاجي على Azure | VAPT مجتاز؛ اختبار استعادة؛ اختبار أحمال؛ شهادة الجودة | الجهة المالكة |

الوسم: `vMAJOR.MINOR.PATCH`، سجل التغييرات في `CHANGELOG.md`، ملاحظات الإصدار لكل جمهور (إداريون، مدربون، متدربون).

---

# Release management (English copy)
**Alpha:** core modules on staging; exit = automated suite green, no critical bugs, demo to the centre; sign-off project manager + centre representative. **Beta:** all modules plus integrations, security and payments (behind flags); exit = role-by-role UAT, security and performance summaries, no high bugs; sign-off acceptance committee. **Final:** production on Azure; exit = VAPT passed, restore test, load test, QA certificate; sign-off owner. Tags `vMAJOR.MINOR.PATCH`; changelog in `CHANGELOG.md`; release notes per audience.
