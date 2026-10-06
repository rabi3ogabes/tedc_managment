/** Communication centre, notification rules and delivery tracking, announcements and events, homepage editor, preferences (Phase 11). */
export const commAr = {
  comm: {
    title: 'مركز التواصل', navHome: 'محرر الصفحة الرئيسية',
    tabs: { send: 'إرسال وجدولة', scheduled: 'المجدولة', rules: 'القواعد', templates: 'القوالب', deliveries: 'التسليم', announcements: 'الإعلانات', events: 'الفعاليات', campaigns: 'الحملات', upcoming: 'القادم' },
    channels: { push: 'إشعار فوري', email: 'بريد إلكتروني', sms: 'رسالة نصية', in_app: 'داخل المنصة' },
    status: { queued: 'في الانتظار', sent: 'أُرسل', delivered: 'وصل', read: 'قُرئ', failed: 'فشل', skipped: 'تم تخطيه', scheduled: 'مجدول', sending: 'جارٍ الإرسال', cancelled: 'ملغى', draft: 'مسودة', published: 'منشور', expired: 'منتهٍ', archived: 'مؤرشف' },
    audience: {
      title: 'الجمهور المستهدف', roles: 'الأدوار', jobTitles: 'المسميات الوظيفية', schools: 'المدارس', schoolGroups: 'مجموعات المدارس', programs: 'المشاركون في برامج', trainers: 'المدربون فقط', supervisors: 'المشرفون فقط',
      reaches: 'يصل إلى {{n}} شخصًا', reachesOne: 'يصل إلى شخص واحد', nobody: 'لا يصل إلى أحد — غيّر الاختيار', hint: 'القيم داخل الفئة الواحدة تتسع (أيّ منها)، والفئات المختلفة تضيّق بعضها. اتركها فارغة للوصول إلى الجميع ضمن صلاحياتك.', sample: 'أمثلة',
    },
    send: {
      title: 'رسالة جديدة', titleAr: 'العنوان بالعربية', titleEn: 'العنوان بالإنجليزية', bodyAr: 'النص بالعربية', bodyEn: 'النص بالإنجليزية', channels: 'القنوات', when: 'الموعد', now: 'الآن (خلال دقيقة)', later: 'في وقت لاحق', repeat: 'التكرار', until: 'حتى تاريخ',
      repeats: { none: 'مرة واحدة', daily: 'يوميًا', weekly: 'أسبوعيًا', monthly: 'شهريًا' }, submit: 'جدولة الإرسال', sentNow: 'ستصل الرسالة خلال دقيقة.', scheduledOk: 'تمت الجدولة.',
      quiet: 'هذا الموعد خارج الساعات المسموحة للقنوات: {{c}}. ستنتظر حتى أقرب وقت مسموح.', need: 'اكتب العنوان بالعربية والإنجليزية.',
    },
    scheduled: { empty: 'لا توجد إشعارات مجدولة.', cancel: 'إلغاء', runs: 'مرات الإرسال', nextAt: 'الموعد', cancelled: 'أُلغيت الجدولة.', failed: 'السبب' },
    rules: {
      title: 'قواعد الإشعارات', hint: 'القاعدة الأخص تفوز: برنامج، ثم فئة برامج، ثم القاعدة العامة. الأحداث الإلزامية لا يستطيع المستخدم إيقافها.', new: 'قاعدة جديدة', empty: 'لا توجد قواعد — تعمل الإشعارات بإعداداتها الافتراضية.',
      event: 'الحدث', allEvents: 'كل الأحداث', program: 'البرنامج', category: 'فئة البرامج', any: 'أي', enabled: 'مفعّلة', disabledNote: 'متوقفة: لن يُرسل هذا الحدث', channels: 'القنوات المسموحة', keepChannels: 'كما هي', delay: 'تأخير (دقائق)',
      quiet: 'الساعات المسموحة', quietOn: 'حصر الإرسال في ساعات محددة', days: 'الأيام', from: 'من', to: 'إلى', quietChannels: 'تنطبق على', mandatory: 'إلزامي', scope: 'النطاق', delete: 'حذف القاعدة؟', saved: 'حُفظت القاعدة.',
      dayNames: ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'], tz: 'بتوقيت الدوحة', sound: 'تنبيه',
    },
    deliveries: {
      title: 'تتبع التسليم', total: 'الإجمالي', recipient: 'المستلم', channel: 'القناة', type: 'النوع', reason: 'السبب', date: 'التاريخ', allChannels: 'كل القنوات', allStatuses: 'كل الحالات', search: 'ابحث بالاسم أو البريد', excel: 'تصدير Excel', pdf: 'تصدير PDF', empty: 'لا توجد سجلات تطابق الاختيار.',
      note: 'الحالة «قُرئ» لرسالة خارج المنصة تعني أن المستلم فتح الإشعار نفسه داخل المنصة.',
    },
    ann: {
      title: 'الإعلانات والأخبار', new: 'إعلان جديد', newEvent: 'فعالية جديدة', types: { news: 'خبر', announcement: 'إعلان', circular: 'تعميم', event: 'فعالية', activity: 'نشاط' },
      search: 'ابحث في العنوان والنص', archiveToggle: 'الأرشيف', pinned: 'المثبّتة', pin: 'تثبيت', unpin: 'إلغاء التثبيت', archive: 'أرشفة', unarchive: 'استعادة', republish: 'إعادة نشر', publish: 'نشر', edit: 'تعديل', window: 'فترة العرض', from: 'يبدأ', to: 'ينتهي',
      reorderHint: 'اسحب المثبّتة لترتيبها.', republishTitle: 'إعادة نشر كإعلان جديد', republishHint: 'تُنسخ كل المحتويات والوسائط، وتبدأ فترة عرض جديدة.', republished: 'تم نشر نسخة جديدة.',
      audiences: { all: 'الجميع', schools: 'مدارس محددة', programs: 'برامج محددة', roles: 'أدوار محددة', filter: 'جمهور مخصص' }, notify: 'عند النشر', notifyPush: 'إشعار فوري', notifyEmail: 'بريد إلكتروني', public: 'ظاهر في الموقع العام',
      exportMinistry: 'تصدير إلى موقع الوزارة', exported: 'صُدّر', exportNow: 'تصدير الآن', exportQueued: 'أُرسل إلى موقع الوزارة.', bodyAr: 'النص بالعربية', bodyEn: 'النص بالإنجليزية', published: 'تم النشر. وصل إلى {{n}} شخصًا.', scheduledOk: 'تمت جدولة النشر.', saved: 'تم الحفظ.',
      media: { title: 'الوسائط', images: 'صور', video: 'فيديو', audio: 'صوت', files: 'ملفات', links: 'روابط', add: 'إضافة', url: 'الرابط', file: 'ملف', saveFirst: 'احفظ الإعلان أولًا ثم أضف الوسائط.', remove: 'حذف', hintSize: 'الملفات الكبيرة: استخدم رابطًا.' },
      event: { title: 'تفاصيل الفعالية', starts: 'تبدأ', ends: 'تنتهي', venueAr: 'المكان بالعربية', venueEn: 'المكان بالإنجليزية', online: 'رابط الحضور عن بعد', registration: 'رابط تسجيل خارجي', capacity: 'السعة', rsvp: 'التسجيل داخل المنصة', reminder: 'تذكير قبل (ساعات)', going: 'مسجّل', waiting: 'قائمة الانتظار', rsvps: 'المسجلون' },
      empty: 'لا توجد عناصر.', archiveEmpty: 'لا شيء في الأرشيف يطابق البحث.', states: { all: 'كل الحالات' },
    },
    ministry: {
      title: 'موقع الوزارة', hint: 'تُرسل الأخبار والفعاليات المعلّمة للتصدير تلقائيًا إلى عنوان الموقع، مع إعادة المحاولة. ويمكن أيضًا تنزيل ملف أو الاشتراك في الخلاصات.', enabled: 'تفعيل التصدير', auto: 'تصدير تلقائي عند النشر', endpoint: 'عنوان الاستقبال (HTTPS)', header: 'اسم ترويسة المفتاح', key: 'مفتاح API', keySet: 'محفوظ — اتركه فارغًا للإبقاء عليه', site: 'اسم المصدر',
      feeds: 'روابط الخلاصات', run: 'إرسال المنتظر الآن', log: 'سجل التصدير', file: 'تنزيل ملف', saved: 'حُفظت الإعدادات.', queued: 'في الانتظار', failed: 'فشل', sent: 'أُرسل',
    },
    prefs: {
      title: 'تفضيلات الإشعارات', hint: 'اختر كيف تصلك الإشعارات الاختيارية. الإشعارات الإلزامية (مثل قرارات القبول والإلغاء) تصلك دائمًا.', group: 'النوع', sound: 'صوت التنبيه عند وصول إشعار جديد', mandatory: '{{n}} إشعارات إلزامية لا يمكن إيقافها', saved: 'تم حفظ التفضيلات.',
      groups: { program: 'البرامج', registration: 'التسجيل', attendance: 'الحضور', approvals: 'الموافقات', announcements: 'الإعلانات والفعاليات', system: 'النظام' },
    },
    home: {
      title: 'محرر الصفحة الرئيسية', page: 'الصفحة', pages: { home: 'الرئيسية', about: 'من نحن' }, blocks: 'الكتل', preview: 'معاينة', desktop: 'حاسوب', mobile: 'جوال', add: 'إضافة كتلة', publish: 'نشر', note: 'ملاحظة النشر', published: 'نُشرت النسخة {{v}}.',
      saved: 'تم حفظ المسودة.', unsaved: 'تعديلات غير محفوظة', save: 'حفظ المسودة', versions: 'النسخ', restore: 'استعادة', restored: 'تمت استعادة النسخة ونشرها.', current: 'المنشورة', visible: 'ظاهرة', hidden: 'مخفية', audiencePublic: 'للجميع', audienceSigned: 'للمسجلين فقط',
      starts: 'تبدأ', ends: 'تنتهي', moveUp: 'أعلى', moveDown: 'أسفل', remove: 'حذف الكتلة', stats: 'الإحصائيات', statsHint: 'الأرقام المعروضة في الصفحة الرئيسية. المصادر الجاهزة تُحسب تلقائيًا، أو أدخل قيمة يحددها المركز.',
      statLabelAr: 'التسمية بالعربية', statLabelEn: 'التسمية بالإنجليزية', source: 'المصدر', value: 'القيمة', key: 'المعرّف', addStat: 'إضافة رقم', computed: 'الحالي', neverPublished: 'لم تُنشر بعد — الموقع يعرض التصميم الافتراضي حتى تنشر.',
      types: { hero_slider: 'شريط رئيسي', stats: 'إحصائيات', featured_programs: 'برامج مميزة', news: 'الأخبار', events: 'الفعاليات', rich_text: 'نص منسّق', cta: 'دعوة لإجراء', logos: 'شعارات الشركاء', faq: 'أسئلة شائعة', video: 'فيديو', custom_html_safe: 'HTML آمن' },
      f: { title: 'العنوان', subtitle: 'العنوان الفرعي', text: 'النص', body: 'المحتوى (HTML بسيط)', limit: 'العدد', slides: 'الشرائح', image: 'رابط الصورة', url: 'الرابط', label: 'نص الزر', items: 'العناصر', question: 'السؤال', answer: 'الإجابة', name: 'الاسم', video_url: 'رابط الفيديو', html: 'HTML', addItem: 'إضافة', ar: 'عربي', en: 'English' },
    },
    events: { title: 'الفعاليات والأنشطة', subtitle: 'فعاليات المركز وأنشطته القادمة', empty: 'لا توجد فعاليات قادمة.', past: 'السابقة', upcoming: 'القادمة', register: 'سجّل حضورك', cancel: 'إلغاء التسجيل', going: 'أنت مسجّل', waitlisted: 'أنت في قائمة الانتظار', seats: 'المقاعد', addToCalendar: 'أضف إلى التقويم', venue: 'المكان', online: 'الحضور عن بعد', externalRegister: 'التسجيل عبر الرابط', loginToRegister: 'سجّل الدخول للتسجيل', calendarAll: 'اشتراك في تقويم الفعاليات', listen: 'استمع', watch: 'شاهد', gallery: 'الصور' },
  },
}

export const commEn = {
  comm: {
    title: 'Communication centre', navHome: 'Homepage editor',
    tabs: { send: 'Send & schedule', scheduled: 'Scheduled', rules: 'Rules', templates: 'Templates', deliveries: 'Deliveries', announcements: 'Announcements', events: 'Events', campaigns: 'Campaigns', upcoming: 'Upcoming' },
    channels: { push: 'Push', email: 'E-mail', sms: 'SMS', in_app: 'In the app' },
    status: { queued: 'Queued', sent: 'Sent', delivered: 'Delivered', read: 'Read', failed: 'Failed', skipped: 'Skipped', scheduled: 'Scheduled', sending: 'Sending', cancelled: 'Cancelled', draft: 'Draft', published: 'Published', expired: 'Expired', archived: 'Archived' },
    audience: {
      title: 'Audience', roles: 'Roles', jobTitles: 'Job titles', schools: 'Schools', schoolGroups: 'School groups', programs: 'Taking part in programs', trainers: 'Trainers only', supervisors: 'Supervisors only',
      reaches: 'Reaches {{n}} people', reachesOne: 'Reaches 1 person', nobody: 'Reaches nobody — change the selection', hint: 'Values inside one category widen the audience (any of them); different categories narrow each other. Leave everything empty to reach everyone within your scope.', sample: 'Examples',
    },
    send: {
      title: 'New message', titleAr: 'Title (Arabic)', titleEn: 'Title (English)', bodyAr: 'Text (Arabic)', bodyEn: 'Text (English)', channels: 'Channels', when: 'When', now: 'Now (within a minute)', later: 'At a later time', repeat: 'Repeat', until: 'Until',
      repeats: { none: 'Once', daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' }, submit: 'Schedule send', sentNow: 'The message goes out within a minute.', scheduledOk: 'Scheduled.',
      quiet: 'This time is outside the allowed hours of: {{c}}. Those messages wait for the next allowed time.', need: 'Write the title in Arabic and English.',
    },
    scheduled: { empty: 'Nothing is scheduled.', cancel: 'Cancel', runs: 'Times sent', nextAt: 'When', cancelled: 'Schedule cancelled.', failed: 'Reason' },
    rules: {
      title: 'Notification rules', hint: 'The most specific rule wins: a program, then a program category, then a general rule. Mandatory events cannot be switched off by users.', new: 'New rule', empty: 'No rules — notifications use their default settings.',
      event: 'Event', allEvents: 'All events', program: 'Program', category: 'Program category', any: 'Any', enabled: 'Enabled', disabledNote: 'Off: this event is not sent', channels: 'Allowed channels', keepChannels: 'As they are', delay: 'Delay (minutes)',
      quiet: 'Allowed hours', quietOn: 'Only send within set hours', days: 'Days', from: 'From', to: 'To', quietChannels: 'Applies to', mandatory: 'Mandatory', scope: 'Scope', delete: 'Delete this rule?', saved: 'Rule saved.',
      dayNames: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], tz: 'Doha time', sound: 'Alert',
    },
    deliveries: {
      title: 'Delivery tracking', total: 'Total', recipient: 'Recipient', channel: 'Channel', type: 'Type', reason: 'Reason', date: 'Date', allChannels: 'All channels', allStatuses: 'All statuses', search: 'Search by name or e-mail', excel: 'Export Excel', pdf: 'Export PDF', empty: 'No records match.',
      note: '"Read" for a message sent outside the app means the person opened the same notification in the app.',
    },
    ann: {
      title: 'Announcements and news', new: 'New announcement', newEvent: 'New event', types: { news: 'News', announcement: 'Announcement', circular: 'Circular', event: 'Event', activity: 'Activity' },
      search: 'Search title and text', archiveToggle: 'Archive', pinned: 'Pinned', pin: 'Pin', unpin: 'Unpin', archive: 'Archive', unarchive: 'Restore', republish: 'Republish', publish: 'Publish', edit: 'Edit', window: 'Display window', from: 'Starts', to: 'Ends',
      reorderHint: 'Drag the pinned ones to order them.', republishTitle: 'Republish as a new announcement', republishHint: 'Everything, media included, is copied and a new display window starts.', republished: 'A new copy was published.',
      audiences: { all: 'Everyone', schools: 'Chosen schools', programs: 'Chosen programs', roles: 'Chosen roles', filter: 'Custom audience' }, notify: 'On publishing', notifyPush: 'Push', notifyEmail: 'E-mail', public: 'Visible on the public site',
      exportMinistry: 'Export to the Ministry website', exported: 'Exported', exportNow: 'Export now', exportQueued: 'Sent to the Ministry website.', bodyAr: 'Text (Arabic)', bodyEn: 'Text (English)', published: 'Published. Reached {{n}} people.', scheduledOk: 'Publishing is scheduled.', saved: 'Saved.',
      media: { title: 'Media', images: 'Images', video: 'Video', audio: 'Audio', files: 'Files', links: 'Links', add: 'Add', url: 'Address', file: 'File', saveFirst: 'Save the announcement first, then add media.', remove: 'Remove', hintSize: 'Large files: use an address.' },
      event: { title: 'Event details', starts: 'Starts', ends: 'Ends', venueAr: 'Venue (Arabic)', venueEn: 'Venue (English)', online: 'Online link', registration: 'External registration link', capacity: 'Capacity', rsvp: 'Registration in the platform', reminder: 'Remind before (hours)', going: 'Going', waiting: 'Waiting list', rsvps: 'Registrations' },
      empty: 'Nothing here.', archiveEmpty: 'Nothing in the archive matches.', states: { all: 'All statuses' },
    },
    ministry: {
      title: 'Ministry website', hint: 'News and events flagged for export are pushed to the site address automatically, with retries. You can also download a file or subscribe to the feeds.', enabled: 'Export on', auto: 'Export automatically when published', endpoint: 'Receiving address (HTTPS)', header: 'Key header name', key: 'API key', keySet: 'Saved — leave empty to keep it', site: 'Source name',
      feeds: 'Feed addresses', run: 'Send what is waiting now', log: 'Export log', file: 'Download a file', saved: 'Settings saved.', queued: 'Queued', failed: 'Failed', sent: 'Sent',
    },
    prefs: {
      title: 'Notification preferences', hint: 'Choose how optional notifications reach you. Mandatory ones (such as acceptance and cancellation decisions) always do.', group: 'Kind', sound: 'Play a chime when a new notification arrives', mandatory: '{{n}} mandatory notifications cannot be switched off', saved: 'Preferences saved.',
      groups: { program: 'Programs', registration: 'Registration', attendance: 'Attendance', approvals: 'Approvals', announcements: 'Announcements and events', system: 'System' },
    },
    home: {
      title: 'Homepage editor', page: 'Page', pages: { home: 'Home', about: 'About' }, blocks: 'Blocks', preview: 'Preview', desktop: 'Desktop', mobile: 'Mobile', add: 'Add a block', publish: 'Publish', note: 'Publishing note', published: 'Version {{v}} published.',
      saved: 'Draft saved.', unsaved: 'Unsaved changes', save: 'Save draft', versions: 'Versions', restore: 'Restore', restored: 'The version was restored and published.', current: 'Live', visible: 'Visible', hidden: 'Hidden', audiencePublic: 'Everyone', audienceSigned: 'Signed-in only',
      starts: 'Starts', ends: 'Ends', moveUp: 'Up', moveDown: 'Down', remove: 'Remove block', stats: 'Statistics', statsHint: 'The numbers on the homepage. Built-in sources are computed automatically, or enter a value the centre sets.',
      statLabelAr: 'Label (Arabic)', statLabelEn: 'Label (English)', source: 'Source', value: 'Value', key: 'Key', addStat: 'Add a number', computed: 'Now', neverPublished: 'Not published yet — the site shows the default design until you publish.',
      types: { hero_slider: 'Hero slider', stats: 'Statistics', featured_programs: 'Featured programs', news: 'News', events: 'Events', rich_text: 'Rich text', cta: 'Call to action', logos: 'Partner logos', faq: 'FAQ', video: 'Video', custom_html_safe: 'Safe HTML' },
      f: { title: 'Title', subtitle: 'Subtitle', text: 'Text', body: 'Content (simple HTML)', limit: 'How many', slides: 'Slides', image: 'Image address', url: 'Link', label: 'Button label', items: 'Items', question: 'Question', answer: 'Answer', name: 'Name', video_url: 'Video address', html: 'HTML', addItem: 'Add', ar: 'Arabic', en: 'English' },
    },
    events: { title: 'Events and activities', subtitle: 'What is coming up at the centre', empty: 'No upcoming events.', past: 'Past', upcoming: 'Upcoming', register: 'Register', cancel: 'Cancel registration', going: 'You are registered', waitlisted: 'You are on the waiting list', seats: 'Seats', addToCalendar: 'Add to calendar', venue: 'Venue', online: 'Online', externalRegister: 'Register via the link', loginToRegister: 'Sign in to register', calendarAll: 'Subscribe to the events calendar', listen: 'Listen', watch: 'Watch', gallery: 'Gallery' },
  },
}
