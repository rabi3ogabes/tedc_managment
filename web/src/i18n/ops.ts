/** System administration: signing in as a user, the error log and the room-screen template. */
export const opsAr = {
  schoolsMap: {
    title: 'المدارس', subtitle: 'جميع مدارس دولة قطر على الخريطة — الحكومية والخاصة — من قائمة وزارة التربية والتعليم «أين مدرستي».',
    tabs: { map: 'الخريطة', list: 'القائمة' }, searchPlaceholder: 'ابحث باسم المدرسة أو الحي…', all: 'الكل', gender: { boys: 'بنين', girls: 'بنات', mixed: 'مختلط' }, genderLabel: 'الفئة',
    kinds: { government: 'حكومية', private: 'خاصة' }, total: '{{count}} مدرسة', shown: 'المعروضة', noResult: 'لا توجد مدارس بهذه المعايير.',
    basemap: { street: 'خريطة', satellite: 'قمر صناعي' }, fit: 'عرض الكل', reset: 'مسح التصفية',
    details: { directions: 'الاتجاهات', copy: 'نسخ الإحداثيات', copied: 'تم النسخ', phone: 'الهاتف', email: 'البريد', website: 'الموقع', address: 'العنوان', curriculum: 'المنهج', staff: 'موظفو المركز في هذه المدرسة', partner: 'مدرسة شريكة', code: 'الرمز', close: 'إغلاق', pick: 'اختر مدرسة من الخريطة لعرض تفاصيلها.' },
    sync: { button: 'تحديث القائمة من الوزارة', last: 'آخر تحديث: {{date}}', never: 'لم تُحدَّث بعد', done: 'اكتمل التحديث: {{created}} جديدة و{{updated}} محدَّثة.', fromMinistry: 'من موقع الوزارة مباشرة', fromBundled: 'من النسخة المرفقة (تعذّر الاتصال بالوزارة)', confirm: 'سيُحدَّث سجل المدارس من «أين مدرستي». تبقى بياناتك الخاصة (الشريك، الشعار، الحالة). متابعة؟' },
  },
  liveGeo: {
    mapTitle: 'خريطة المتصلين الآن', mapHint: 'تُحدَّد المواقع من شبكة المستخدم (المدينة والدولة) دون تخزين العنوان الدقيق.', people: '{{count}} متصل', unknown: 'موقع غير معروف', unlocated: '{{count}} بلا موقع',
    sources: { app: 'تطبيق الجوال', desktop: 'حاسوب', mobile_web: 'متصفح الجوال', tablet: 'جهاز لوحي' }, sourceTitle: 'من أين يدخلون', countries: 'الدول',
    toggle: 'التتبع المباشر', on: 'يعمل', off: 'متوقف', offTitle: 'التتبع المباشر متوقف', offText: 'لا تُسجَّل الجلسات ولا المواقع حالياً. فعّله لترى من يتصل الآن وأين.', turnOn: 'تفعيل التتبع',
    turnOff: 'إيقاف التتبع المباشر؟ تتوقف الخريطة وقائمة المتصلين عن التحديث ولا تُسجَّل جلسات جديدة.', locations: 'جمع المواقع', locationsHint: 'عند الإيقاف يبقى التتبع لكن بلا خريطة.',
    joined: 'دخل', left: 'غادر', feed: 'آخر الأحداث', feedEmpty: 'ستظهر هنا حركة الدخول والخروج لحظة بلحظة.', noMap: 'لا مواقع متاحة بعد — تظهر المواقع حين يدخل المستخدمون عبر الإنترنت.', auto: 'يتحدث تلقائياً كل 5 ثوانٍ',
  },
  logs: {
    nav: 'سجل الأخطاء', title: 'سجل الأخطاء', subtitle: 'كل ما تعثّر في النظام أو الموقع أو التطبيق، مجمّعاً بحسب السبب — يظهر لمدير النظام فقط. تُعالَج المشكلات المعروفة تلقائياً.',
    stats: { open: 'مفتوحة', critical: 'حرجة', today: 'اليوم', auto: 'عولجت تلقائياً', trend: 'آخر 14 يوماً' },
    status: { open: 'مفتوحة', fixed: 'تم إصلاحها', ignored: 'متجاهَلة', all: 'الكل' }, source: { all: 'كل المصادر', server: 'النظام', web: 'الموقع', app: 'التطبيق' },
    level: { all: 'كل المستويات', critical: 'حرج', error: 'خطأ', warning: 'تحذير' }, anyTime: 'أي وقت', lastDays: 'آخر {{count}} يوم', sort: { recent: 'الأحدث', frequent: 'الأكثر تكراراً', users: 'الأكثر تأثيراً' },
    search: 'ابحث في الرسالة أو المكان أو البريد…', total: '{{count}} مجموعة أخطاء', selected: '{{count}} محدد', emptyOpen: 'لا أخطاء مفتوحة. كل شيء يعمل 👌', empty: 'لا توجد نتائج.',
    markFixed: 'تم الإصلاح', ignore: 'تجاهل', reopen: 'إعادة فتح', tryFix: 'إصلاح تلقائي الآن', fixable: 'قابل للإصلاح تلقائياً', auto: 'تلقائي', autoFixed: 'عولج هذا الخطأ تلقائياً.', clearFixed: 'حذف المُصلَحة',
    confirmDelete: 'حذف هذا الخطأ نهائياً؟', confirmDeleteMany: 'حذف الأخطاء المحددة نهائياً؟', prev: 'السابق', next: 'التالي',
    occurrences: 'مرات الحدوث', usersAffected: '{{count}} مستخدم', lastSeen: 'آخر حدوث', firstSeen: 'أول حدوث', location: 'المكان', request: 'الطلب', statusCode: 'رمز الحالة', user: 'المستخدم', version: 'إصدار التطبيق', exception: 'نوع الخطأ', ip: 'العنوان',
    device: 'الجهاز', context: 'السياق', stack: 'تفاصيل الخطأ', copy: 'نسخ', note: 'ملاحظة',
    settings: { button: 'الإعدادات', title: 'إعدادات سجل الأخطاء', enabled: 'تسجيل الأخطاء', enabledHint: 'إيقافه يوقف حفظ أي خطأ جديد.', autoFix: 'الإصلاح التلقائي', autoFixHint: 'تنفيذ العلاج المعروف للمشكلة (ترحيل قاعدة البيانات، مسح الذاكرة المؤقتة، تنظيف رموز الإشعارات القديمة).', clients: 'أخطاء الموقع والتطبيق', clientsHint: 'استقبال ما يبلّغ به الموقع والتطبيق عن أنفسهما.', retention: 'مدة الاحتفاظ بالمُصلَح (يوم)', retentionHint: 'تُحذف الأخطاء المُصلَحة أو المتجاهَلة الأقدم من ذلك تلقائياً.' },
  },
  impersonation: {
    signInAs: 'الدخول بحسابه', confirm: 'ستفتح حساب «{{name}}» ({{email}}) وتتصرف باسمه لمدة ساعة. تُسجَّل البداية والنهاية في سجل التدقيق. متابعة؟',
    banner: 'أنت داخل حساب {{name}} ({{email}})', left: 'متبقٍ {{minutes}} د', stop: 'العودة إلى حسابي',
  },
}

export const opsEn: typeof opsAr = {
  schoolsMap: {
    title: 'Schools', subtitle: 'Every school in Qatar on one map — government and private — from the Ministry of Education “Where is my school” list.',
    tabs: { map: 'Map', list: 'List' }, searchPlaceholder: 'Search by school or district…', all: 'All', gender: { boys: 'Boys', girls: 'Girls', mixed: 'Mixed' }, genderLabel: 'Gender',
    kinds: { government: 'Government', private: 'Private' }, total: '{{count}} schools', shown: 'shown', noResult: 'No schools match these filters.',
    basemap: { street: 'Map', satellite: 'Satellite' }, fit: 'Show all', reset: 'Clear filters',
    details: { directions: 'Directions', copy: 'Copy coordinates', copied: 'Copied', phone: 'Phone', email: 'Email', website: 'Website', address: 'Address', curriculum: 'Curriculum', staff: 'Center staff at this school', partner: 'Partner school', code: 'Code', close: 'Close', pick: 'Pick a school on the map to see its details.' },
    sync: { button: 'Update list from the Ministry', last: 'Last update: {{date}}', never: 'Not updated yet', done: 'Update complete: {{created}} new and {{updated}} refreshed.', fromMinistry: 'straight from the Ministry site', fromBundled: 'from the bundled copy (the Ministry could not be reached)', confirm: 'The school register will be refreshed from “Where is my school”. Your own data (partner, logo, status) is kept. Continue?' },
  },
  liveGeo: {
    mapTitle: 'Who is online, on the map', mapHint: 'Places come from the user’s network (city and country); the exact address is never stored.', people: '{{count}} online', unknown: 'Unknown place', unlocated: '{{count}} without a location',
    sources: { app: 'Mobile app', desktop: 'Desktop', mobile_web: 'Phone browser', tablet: 'Tablet' }, sourceTitle: 'Where they come in from', countries: 'Countries',
    toggle: 'Live tracking', on: 'On', off: 'Off', offTitle: 'Live tracking is off', offText: 'Sessions and locations are not being recorded. Turn it on to see who is online and where.', turnOn: 'Turn tracking on',
    turnOff: 'Turn live tracking off? The map and the online list stop updating and no new sessions are recorded.', locations: 'Collect locations', locationsHint: 'When off, tracking continues but without the map.',
    joined: 'joined', left: 'left', feed: 'Latest activity', feedEmpty: 'Arrivals and departures show up here as they happen.', noMap: 'No locations yet — they appear as users connect over the internet.', auto: 'Updates automatically every 5 seconds',
  },
  logs: {
    nav: 'Error log', title: 'Error log', subtitle: 'Everything that failed in the system, the website or the app, grouped by cause — visible to the system administrator only. Known problems are fixed automatically.',
    stats: { open: 'Open', critical: 'Critical', today: 'Today', auto: 'Fixed automatically', trend: 'Last 14 days' },
    status: { open: 'Open', fixed: 'Fixed', ignored: 'Ignored', all: 'All' }, source: { all: 'All sources', server: 'System', web: 'Website', app: 'App' },
    level: { all: 'All levels', critical: 'Critical', error: 'Error', warning: 'Warning' }, anyTime: 'Any time', lastDays: 'Last {{count}} days', sort: { recent: 'Most recent', frequent: 'Most frequent', users: 'Most users affected' },
    search: 'Search the message, place or e-mail…', total: '{{count}} error groups', selected: '{{count}} selected', emptyOpen: 'No open errors. All is well 👌', empty: 'No results.',
    markFixed: 'Mark fixed', ignore: 'Ignore', reopen: 'Reopen', tryFix: 'Fix automatically now', fixable: 'Can be fixed automatically', auto: 'Auto', autoFixed: 'This error was fixed automatically.', clearFixed: 'Delete fixed',
    confirmDelete: 'Delete this error permanently?', confirmDeleteMany: 'Delete the selected errors permanently?', prev: 'Previous', next: 'Next',
    occurrences: 'Occurrences', usersAffected: '{{count}} users', lastSeen: 'Last seen', firstSeen: 'First seen', location: 'Where', request: 'Request', statusCode: 'Status code', user: 'User', version: 'App version', exception: 'Error type', ip: 'Address',
    device: 'Device', context: 'Context', stack: 'Details', copy: 'Copy', note: 'Note',
    settings: { button: 'Settings', title: 'Error log settings', enabled: 'Record errors', enabledHint: 'Switching it off stops saving any new error.', autoFix: 'Automatic fixing', autoFixHint: 'Apply the known remedy of a problem (database migration, clearing caches, removing stale push tokens).', clients: 'Website and app errors', clientsHint: 'Accept what the website and the app report about themselves.', retention: 'Keep fixed entries for (days)', retentionHint: 'Fixed or ignored errors older than this are deleted automatically.' },
  },
  impersonation: {
    signInAs: 'Sign in as', confirm: 'You will open the account of “{{name}}” ({{email}}) and act as them for one hour. The start and the end are written to the audit log. Continue?',
    banner: 'You are inside the account of {{name}} ({{email}})', left: '{{minutes}} min left', stop: 'Return to my account',
  },
}
