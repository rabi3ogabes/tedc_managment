# Ministry e-payment gateway — certification plan (PAY-02)

> العربية أولًا، ثم English. The automated suite below runs the whole plan against the mock; the Ministry runs the same cases against its sandbox.

## العربية

### الغرض
قبل تفعيل الدفع الحقيقي تُشغَّل **خطة اعتماد** من تسع حالات. تعمل أولًا آليًا على الخادم التجريبي (على طلبات وهمية تُلغى بعد الانتهاء ولا تصل إلى أي بوابة حقيقية)، ثم تُكرَّر مع الوزارة على بيئتها التجريبية.

### كيف تُشغَّل
- من الواجهة: **الإعدادات ← الدفع ← «تشغيل خطة الاعتماد على الخادم التجريبي»** (لا تعمل في بيئة الإنتاج).
- من الطرفية: `php artisan tedc:payments-certify` (يعيد رمز فشل إن فشلت حالة).
- في الاختبارات: `MoeEpayCertificationTest`.

تتبع الخطة **العقد المعتمد حاليًا**: إن عدّلت المسارات أو التوقيع أو جدول الحالات أو شكل النداء (`inbound.samples`) فستعمل الخطة بما عدّلته، وتُظهر أي حالة يكسرها تعديل خاطئ. ويمكن لصق نماذج الوزارة الحقيقية في «حالات الاختبار» لتجيب الخطة بها.

### الحالات
| # | الحالة | الخطوات | النتيجة المتوقعة |
|---|---|---|---|
| 1 | دفعة ناجحة | بدء الدفع ← نداء موقَّع بحالة النجاح وبالمبلغ الصحيح | الطلب «مدفوع»، الدفعة «محصّلة»، وصدرت فاتورة |
| 2 | رفض الدفع | نداء موقَّع بحالة الرفض | الطلب «فشل» ولا مبلغ مخصوم ولا فاتورة |
| 3 | إلغاء من المشتري | نداء موقَّع بحالة الإلغاء | الطلب «ملغى» والدفعة غير محصّلة |
| 4 | انتهاء المهلة | بوابة لا تجيب؛ وطلب تركه المشتري حتى انتهت مهلته | خطأ مهلة قابل لإعادة المحاولة برسالة واضحة بلا أسرار؛ والطلب المنتهي يُلغى ويُفرج عن مقاعده |
| 5 | نداء مكرر | النداء نفسه مرتين برقم الحدث نفسه | الأول يُطبَّق، والثاني «مكرر» ولا يغيّر شيئًا؛ يُسجَّل الحدث مرة واحدة |
| 6 | مبلغ لا يطابق | نداء نجاح بمبلغ أقل من الطلب | لا يُصدَّق: الطلب لا يُدفع ويُخطَر المالية |
| 7 | استرداد كلي | طلب استرداد بكامل المبلغ بمفتاح عدم تكرار | تعيد البوابة مرجع الاسترداد |
| 8 | استرداد جزئي | استردادان جزئيان بمفتاحين مختلفين؛ واسترداد أكبر من المحصّل | لكل جزئي مرجع؛ والأكبر يُرفض بسبب البوابة |
| 9 | استيراد كشف المطابقة | ملف كشف (CSV بعلامة BOM) فيه دفعة لم يصل ندازها، ودفعة معروفة، ومرجع مجهول | تُستكمل الدفعة الضائعة، وتُطابَق المعروفة، ويُبلَّغ عن المجهول؛ واستيراد الملف نفسه ثانية لا يغيّر شيئًا |

### ما تؤكده الوزارة في الاختبار المشترك
- نداء حقيقي موقَّع من البوابة يحوّل طلبًا تجريبيًا إلى «مدفوع» مرة واحدة فقط.
- شكل المبلغ (منزلتان أو ثلاث، بالريال أو بالدرهم) ولغة الصفحة المستضافة وأكواد الحالة.
- طريقة التوقيع (HMAC-SHA256/512 أو RSA-SHA256) واسم الترويسة.
- شكل ملف المطابقة (أو واجهة الكشف) ودوريته.

### التراجع
إيقاف التكامل من البطاقة يعيد الدفع إلى «غير متاح» في الإنتاج دون حذف أي طلب؛ والرجوع إلى نسخة العقد السابقة من تبويب «العقد».

## English

### Purpose
Before real payment is switched on, a **certification plan** of nine cases is run: first automatically against the mock (on pretend orders that are rolled back and never reach a real gateway), then again with the Ministry on its sandbox.

### How to run it
- In the app: **Settings → Payments → “Run the certification plan against the mock”** (it does not run in production).
- From the terminal: `php artisan tedc:payments-certify` (exits with a failure when a case fails).
- In the tests: `MoeEpayCertificationTest`.

The plan follows **the contract in force**: if you change the paths, the signature, the status table or the callback shape (`inbound.samples`), the plan runs with your change and shows which case a wrong edit breaks. The Ministry's real samples can be pasted into the test cases for the plan to answer with.

### The cases
| # | Case | Steps | Expected |
|---|---|---|---|
| 1 | A successful payment | start the payment → a signed callback with the success status and the right amount | the order is paid, the payment captured, an invoice issued |
| 2 | A declined payment | a signed callback with the decline status | the order failed, nothing charged, no invoice |
| 3 | A cancellation by the buyer | a signed callback with the cancel status | the order is cancelled, the payment not captured |
| 4 | A timeout | a gateway that does not answer; an order the buyer abandoned until it expired | a retryable timeout with a clear message and no secret; the expired order is cancelled and its seats released |
| 5 | A repeated callback | the same callback twice with the same event id | the first is applied, the second is “duplicate” and changes nothing; the event is recorded once |
| 6 | An amount that does not match | a success callback for less than the order | not believed: the order is not paid and finance is told |
| 7 | A full refund | a refund of the whole amount with an idempotency key | the gateway returns a refund reference |
| 8 | A partial refund | two partial refunds with different keys; and a refund above what was captured | each partial gets a reference; the larger one is refused with the gateway's reason |
| 9 | Importing the reconciliation statement | a statement file (CSV with a BOM) holding a payment whose callback never arrived, a known payment and an unknown reference | the lost payment is completed, the known one matched, the unknown reported; importing the same file again changes nothing |

### To confirm with the Ministry in the joint test
- A real signed callback from the gateway turns a test order to paid, once only.
- The amount format (two or three decimals, riyals or dirhams), the hosted page language and the status codes.
- The signing method (HMAC-SHA256/512 or RSA-SHA256) and the header name.
- The format of the reconciliation file (or the statement interface) and how often it comes.

### Rollback
Switching the integration off on the card makes payment “unavailable” in production without deleting any order; the previous contract version can be restored from the Contract tab.
