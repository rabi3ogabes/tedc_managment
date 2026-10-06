/** Buying courses: prices, cart, checkout, orders, vouchers, entities, refunds, finance (Phase 16). */
export const payAr = {
  pay: {
    nav: { orders: 'طلباتي وقسائمي', entity: 'حساب الجهة', finance: 'المدفوعات والمالية', pricing: 'الأسعار' },
    price: { free: 'مجاني', freeForYou: 'مجاني لك', from: 'من {{p}}', range: '{{a}} – {{b}}', vat: 'شامل الضريبة', currency: 'ر.ق' },
    add: { button: 'أضف إلى السلة', group: 'اختر المجموعة', noGroups: 'لا توجد مجموعات مفتوحة للشراء.', added: 'أُضيف إلى السلة — المقعد محجوز لك لبضع دقائق.', seats: '{{n}} مقعد متاح', quantity: 'عدد المقاعد', login: 'سجّل الدخول للشراء' },
    cart: {
      title: 'السلة', open: 'السلة', empty: 'سلتك فارغة.', remove: 'إزالة', holdUntil: 'المقعد محجوز حتى {{t}}', subtotal: 'المجموع', discount: 'الخصم', vat: 'الضريبة', total: 'الإجمالي', code: 'رمز الخصم', apply: 'تطبيق', codeApplied: 'طُبّق الرمز {{c}}.', checkout: 'ادفع الآن', free: 'تأكيد التسجيل المجاني',
      redirecting: 'جارٍ تحويلك إلى بوابة الدفع الآمنة…', secure: 'يتم الدفع على صفحة بوابة الدفع الإلكتروني للوزارة. لا نحتفظ ببيانات بطاقتك.', paidOk: 'اكتمل الطلب وتم تأكيد تسجيلك.', qty: 'الكمية', rule: 'الفئة',
    },
    ret: { title: 'حالة الدفع', checking: 'جارٍ التحقق من الدفع…', paid: 'تم الدفع بنجاح', paidHint: 'تم تأكيد مقعدك وأُرسلت الفاتورة إلى طلباتك.', failed: 'لم تكتمل عملية الدفع', failedHint: 'لم يُخصم أي مبلغ. يمكنك المحاولة مرة أخرى.', cancelled: 'أُلغي الدفع', pending: 'ما زلنا ننتظر تأكيد البوابة. لا تُعد الدفع — سيصلك إشعار.', order: 'رقم الطلب', myOrders: 'طلباتي', home: 'الرئيسية' },
    fake: { title: 'بوابة الدفع التجريبية', hint: 'هذه صفحة تدريب تحاكي بوابة الوزارة. لا يوجد خصم حقيقي.', pay: 'تأكيد الدفع', fail: 'محاكاة فشل', cancel: 'إلغاء' },
    orders: {
      title: 'طلباتي', empty: 'لا توجد طلبات بعد.', number: 'رقم الطلب', date: 'التاريخ', total: 'الإجمالي', status: 'الحالة', invoice: 'الفاتورة', creditNote: 'إشعار دائن', refund: 'طلب استرداد', refundTitle: 'طلب استرداد', refundable: 'المبلغ القابل للاسترداد حسب السياسة: {{a}}',
      reason: 'السبب (اختياري)', send: 'إرسال الطلب', sent: 'أُرسل طلب الاسترداد إلى المالية.', refunds: 'طلبات الاسترداد', st: { pending_payment: 'بانتظار الدفع', paid: 'مدفوع', failed: 'فشل', cancelled: 'ملغى', refunded: 'مُسترد', partially_refunded: 'مُسترد جزئيًا' },
      rf: { requested: 'قيد المراجعة', approved: 'موافق عليه', rejected: 'مرفوض', refunded: 'تم الاسترداد', failed: 'تعذّر' }, nothing: 'لا يمكن استرداد شيء الآن وفق السياسة.',
    },
    vouchers: { title: 'قسائم المقاعد', redeem: 'استخدام قسيمة', code: 'رمز القسيمة', use: 'تسجيل بالقسيمة', redeemed: 'تم تسجيلك بالقسيمة.', mine: 'قسائمي', expires: 'تنتهي {{d}}', none: 'لا قسائم مسندة إليك.', st: { available: 'متاحة', assigned: 'مسندة', redeemed: 'مستخدمة', expired: 'منتهية', refunded: 'مستردة' } },
    entity: {
      title: 'حساب الجهة', pick: 'الجهة', none: 'لا ترتبط بأي حساب جهة. تواصل مع المركز لإنشاء حساب لمدرستك أو جهتك.', buy: 'شراء مقاعد', program: 'ابحث عن برنامج', group: 'المجموعة', qty: 'عدد المقاعد', add: 'أضف إلى سلة الجهة', cart: 'سلة الجهة',
      usage: 'الاستخدام', total: 'إجمالي المقاعد', redeemedN: 'مستخدمة', unused: 'غير مستخدمة', expired: 'منتهية', spent: 'المصروف', vouchers: 'القسائم', assign: 'إسناد', assignHint: 'أدخل سطرًا لكل شخص: رقمه الوظيفي أو بريده الإلكتروني (يمكنك لصق قائمة).', assignBtn: 'إسناد القسائم', assigned: 'أُسندت {{n}} قسيمة.', failedRows: 'تعذّر {{n}} سطر', person: 'الشخص', orders: 'الطلبات', checkout: 'ادفع للجهة',
    },
    admin: {
      title: 'المدفوعات والمالية', tabs: { orders: 'الطلبات', payments: 'المدفوعات', refunds: 'الاسترداد', codes: 'رموز الخصم', entities: 'الجهات', recon: 'المطابقة', report: 'التقارير', settings: 'الإعدادات' },
      buyer: 'المشتري', amount: 'المبلغ', gateway: 'البوابة', ref: 'المرجع', sig: 'التوقيع', search: 'ابحث برقم الطلب أو الفاتورة', all: 'الكل',
      approve: 'موافقة', reject: 'رفض', note: 'ملاحظة / سبب', decided: 'تم القرار.', needReason: 'اكتب سبب الرفض.',
      code: 'الرمز', type: 'النوع', value: 'القيمة', scope: 'النطاق', scopes: { all: 'كل البرامج', program: 'برنامج', group: 'مجموعة' }, types: { percent: 'نسبة ٪', amount: 'مبلغ' }, used: 'استُخدم', limit: 'الحد', perUser: 'لكل شخص', validTo: 'ينتهي', newCode: 'رمز جديد', saved: 'تم الحفظ.', scopeId: 'معرّف البرنامج/المجموعة',
      entityName: 'الاسم', adminsEmail: 'بريد المسؤولين (واحد بكل سطر)', cr: 'السجل التجاري', newEntity: 'جهة جديدة', etype: { private_school: 'مدرسة خاصة', company: 'شركة', other: 'أخرى' },
      reconRun: 'مطابقة الآن', day: 'اليوم', matched: 'مطابقة', fixed: 'مُصحّحة', diffs: 'اختلافات',
      from: 'من', to: 'إلى', gross: 'الإجمالي', netRev: 'الصافي', refunded: 'المردود', outstanding: 'غير مسدد', discounts: 'الخصومات', revByProgram: 'الإيراد حسب البرنامج', revByCategory: 'الإيراد حسب الفئة', revByEntity: 'الإيراد حسب الجهة', seats: 'المقاعد', revenue: 'الإيراد', export: 'تصدير',
      set: { hold: 'مدة حجز المقعد في السلة (دقائق)', order: 'مهلة الدفع (دقائق)', skip: 'التسجيل المدفوع يتجاوز موافقة المدير', voucherDays: 'صلاحية القسيمة (أيام)', reminder: 'التنبيه قبل الانتهاء (أيام)', vat: 'ضريبة افتراضية ٪', sellerAr: 'اسم البائع (عربي)', sellerEn: 'اسم البائع (إنجليزي)', tax: 'الرقم الضريبي', email: 'بريد المالية' },
    },
    pricing: {
      title: 'أسعار البرنامج', program: 'سعر البرنامج (لكل المجموعات)', group: 'مجموعة', defaultPrice: 'السعر الافتراضي', vat: 'ضريبة القيمة المضافة ٪', rules: 'قواعد السعر حسب الفئة', rulesHint: 'تُجرَّب بالترتيب، وأول قاعدة تنطبق تحدد السعر. السعر صفر يعني مجانًا لهذه الفئة.', add: 'إضافة قاعدة', cat: 'الفئة', price: 'السعر', label: 'التسمية',
      categories: { ministry_staff: 'موظفو الوزارة (مدارس حكومية)', private_school: 'مدارس خاصة', external: 'أفراد خارجيون', entity: 'جهات (لكل مقعد)', any: 'أي فئة' }, refundPolicy: 'سياسة الاسترداد', fullDays: 'استرداد كامل حتى (أيام قبل البدء)', partialDays: 'استرداد جزئي حتى (أيام)', partialPct: 'نسبة الاسترداد الجزئي ٪',
      preview: 'معاينة', previewRow: '{{c}}: {{p}}', free: 'مجاني', remove: 'حذف قائمة الأسعار', removed: 'حُذفت قائمة الأسعار.', none: 'لا توجد قائمة أسعار — البرنامج مجاني للجميع.', create: 'إنشاء قائمة أسعار', inherit: 'تستخدم هذه المجموعة سعر البرنامج',
    },
    common: { loading: 'جارٍ التحميل…', cancel: 'إلغاء', save: 'حفظ', close: 'إغلاق', failed: 'تعذّر تنفيذ العملية.', qar: 'ر.ق' },
  },
}

export const payEn = {
  pay: {
    nav: { orders: 'Orders & vouchers', entity: 'Entity account', finance: 'Payments & finance', pricing: 'Prices' },
    price: { free: 'Free', freeForYou: 'Free for you', from: 'From {{p}}', range: '{{a}} – {{b}}', vat: 'incl. VAT', currency: 'QAR' },
    add: { button: 'Add to cart', group: 'Choose the group', noGroups: 'No groups are open for purchase.', added: 'Added to your cart — the seat is held for you for a few minutes.', seats: '{{n}} seats left', quantity: 'Seats', login: 'Sign in to buy' },
    cart: {
      title: 'Cart', open: 'Cart', empty: 'Your cart is empty.', remove: 'Remove', holdUntil: 'Seat held until {{t}}', subtotal: 'Subtotal', discount: 'Discount', vat: 'VAT', total: 'Total', code: 'Discount code', apply: 'Apply', codeApplied: 'Code {{c}} applied.', checkout: 'Pay now', free: 'Confirm free registration',
      redirecting: 'Taking you to the secure payment gateway…', secure: 'You pay on the Ministry e-payment gateway page. We never keep your card details.', paidOk: 'Order complete — your registration is confirmed.', qty: 'Qty', rule: 'Category',
    },
    ret: { title: 'Payment status', checking: 'Checking your payment…', paid: 'Payment successful', paidHint: 'Your seat is confirmed and the invoice is in your orders.', failed: 'Payment was not completed', failedHint: 'Nothing was charged. You can try again.', cancelled: 'Payment cancelled', pending: 'Still waiting for the gateway to confirm. Do not pay again — you will be notified.', order: 'Order', myOrders: 'My orders', home: 'Home' },
    fake: { title: 'Training payment gateway', hint: 'This is a training page that imitates the Ministry gateway. Nothing is charged.', pay: 'Confirm payment', fail: 'Simulate failure', cancel: 'Cancel' },
    orders: {
      title: 'My orders', empty: 'No orders yet.', number: 'Order', date: 'Date', total: 'Total', status: 'Status', invoice: 'Invoice', creditNote: 'Credit note', refund: 'Request refund', refundTitle: 'Request a refund', refundable: 'Refundable under the policy: {{a}}',
      reason: 'Reason (optional)', send: 'Send request', sent: 'Your refund request was sent to finance.', refunds: 'Refund requests', st: { pending_payment: 'Awaiting payment', paid: 'Paid', failed: 'Failed', cancelled: 'Cancelled', refunded: 'Refunded', partially_refunded: 'Partly refunded' },
      rf: { requested: 'Under review', approved: 'Approved', rejected: 'Declined', refunded: 'Refunded', failed: 'Failed' }, nothing: 'Nothing can be refunded now under the policy.',
    },
    vouchers: { title: 'Seat vouchers', redeem: 'Use a voucher', code: 'Voucher code', use: 'Register with voucher', redeemed: 'You are registered with the voucher.', mine: 'My vouchers', expires: 'Expires {{d}}', none: 'No vouchers assigned to you.', st: { available: 'Available', assigned: 'Assigned', redeemed: 'Used', expired: 'Expired', refunded: 'Refunded' } },
    entity: {
      title: 'Entity account', pick: 'Entity', none: 'You are not linked to an entity account. Ask the centre to create one for your school or organisation.', buy: 'Buy seats', program: 'Find a programme', group: 'Group', qty: 'Seats', add: 'Add to entity cart', cart: 'Entity cart',
      usage: 'Usage', total: 'Total seats', redeemedN: 'Used', unused: 'Unused', expired: 'Expired', spent: 'Spent', vouchers: 'Vouchers', assign: 'Assign', assignHint: 'One line per person: employee number or e-mail (you can paste a list).', assignBtn: 'Assign vouchers', assigned: '{{n}} vouchers assigned.', failedRows: '{{n}} rows failed', person: 'Person', orders: 'Orders', checkout: 'Pay for the entity',
    },
    admin: {
      title: 'Payments & finance', tabs: { orders: 'Orders', payments: 'Payments', refunds: 'Refunds', codes: 'Discount codes', entities: 'Entities', recon: 'Reconciliation', report: 'Reports', settings: 'Settings' },
      buyer: 'Buyer', amount: 'Amount', gateway: 'Gateway', ref: 'Reference', sig: 'Signature', search: 'Search order or invoice number', all: 'All',
      approve: 'Approve', reject: 'Decline', note: 'Note / reason', decided: 'Decision recorded.', needReason: 'Write the reason for declining.',
      code: 'Code', type: 'Type', value: 'Value', scope: 'Scope', scopes: { all: 'All programmes', program: 'Programme', group: 'Group' }, types: { percent: 'Percent %', amount: 'Amount' }, used: 'Used', limit: 'Limit', perUser: 'Per person', validTo: 'Valid to', newCode: 'New code', saved: 'Saved.', scopeId: 'Programme / group id',
      entityName: 'Name', adminsEmail: 'Administrator e-mails (one per line)', cr: 'Commercial register', newEntity: 'New entity', etype: { private_school: 'Private school', company: 'Company', other: 'Other' },
      reconRun: 'Reconcile now', day: 'Day', matched: 'Matched', fixed: 'Fixed', diffs: 'Differences',
      from: 'From', to: 'To', gross: 'Gross', netRev: 'Net', refunded: 'Refunded', outstanding: 'Outstanding', discounts: 'Discounts', revByProgram: 'Revenue by programme', revByCategory: 'Revenue by category', revByEntity: 'Revenue by entity', seats: 'Seats', revenue: 'Revenue', export: 'Export',
      set: { hold: 'Seat hold in the cart (minutes)', order: 'Time to pay (minutes)', skip: 'Paid registration skips the manager’s approval', voucherDays: 'Voucher validity (days)', reminder: 'Warn before expiry (days)', vat: 'Default VAT %', sellerAr: 'Seller name (Arabic)', sellerEn: 'Seller name (English)', tax: 'Tax ID', email: 'Finance e-mail' },
    },
    pricing: {
      title: 'Programme prices', program: 'Programme price (all groups)', group: 'Group', defaultPrice: 'Default price', vat: 'VAT %', rules: 'Price rules by category', rulesHint: 'Tried in order; the first that applies sets the price. A price of zero means free for that category.', add: 'Add rule', cat: 'Category', price: 'Price', label: 'Label',
      categories: { ministry_staff: 'Ministry staff (government schools)', private_school: 'Private schools', external: 'External individuals', entity: 'Entities (per seat)', any: 'Any category' }, refundPolicy: 'Refund policy', fullDays: 'Full refund until (days before start)', partialDays: 'Partial refund until (days)', partialPct: 'Partial refund %',
      preview: 'Preview', previewRow: '{{c}}: {{p}}', free: 'Free', remove: 'Delete price list', removed: 'Price list deleted.', none: 'No price list — the programme is free for everyone.', create: 'Create price list', inherit: 'This group uses the programme price',
    },
    common: { loading: 'Loading…', cancel: 'Cancel', save: 'Save', close: 'Close', failed: 'That did not work.', qar: 'QAR' },
  },
}
