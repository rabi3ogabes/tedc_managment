/** Strings for سابر+ — the programmes & groups workbench (docs/saber). */
export const saberAr = {
  nav: 'سابر+',
  title: 'سابر+',
  subtitle: 'كل البرامج ومجموعاتها وأيامها التدريبية في شاشة واحدة: التاريخ والقاعة والمدرب لكل يوم، تُحفظ فور اختيارها وتُفحص مع التقويم والحجوزات.',
  stats: { programs: 'برامج هذا الشهر', awaiting: 'مجموعات بانتظار الاعتماد', rooms: 'قاعات محجوزة هذا الشهر', trainers: 'مدربون متاحون' },
  filters: {
    search: 'ابحث باسم البرنامج أو رمزه', from: 'من', to: 'إلى', term: 'الفصل', first: 'الفصل الأول', second: 'الفصل الثاني', kit: 'الحقيبة', ready: 'جاهزة', notReady: 'غير جاهزة', clear: 'مسح عوامل التصفية',
  },
  view: { rows: 'صفوف', cards: 'بطاقات', label: 'عرض الأيام' },
  tools: { title: 'تخصيص شريط الأدوات', hint: 'اختر الأدوات الظاهرة؛ يُحفظ اختيارك على هذا الجهاز.', plan: 'خطة الأيام القادمة', cancel: 'إلغاء البرامج', inbox: 'طلبات أيام الإجازة', weekly: 'الإجازات الأسبوعية', search: 'البحث', dates: 'نطاق التاريخ', term: 'الفصل', kit: 'جاهزية الحقيبة', view: 'طريقة العرض', expand: 'توسيع الكل' },
  expandAll: 'توسيع الكل', collapseAll: 'طي الكل',
  results: 'البرامج: {{n}}', empty: 'لا برامج تطابق عوامل التصفية.', emptyHint: 'غيّر البحث أو امسح عوامل التصفية.',
  program: {
    hours: '{{n}} ساعة', days: 'أيام المجموعة: {{n}}', groups: 'المجموعات: {{n}}', noDates: 'بلا تواريخ بعد', supervisor: 'المشرف', noSupervisor: 'بلا مشرف', supervisorLocked: 'تعديل المشرف موقوف من الإعدادات',
    kitReady: 'الحقيبة جاهزة', kitNotReady: 'الحقيبة غير جاهزة', kitAuto: 'تتبع حالة الحقيبة المرتبطة', cancelled: 'ملغى', open: 'فتح صفحة البرنامج', daysPerGroup: 'عدد الأيام لكل مجموعة',
  },
  lights: { dates: 'أيام لها تاريخ', rooms: 'أيام لها قاعة', trainers: 'أيام لها مدرب', of: '{{n}} من {{total}}' },
  group: {
    n: 'المجموعة {{n}}', number: 'رقم المجموعة', name: 'اسم المجموعة', colour: 'لون المجموعة', term: 'الفصل', termAuto: 'تلقائي من التاريخ', terms: { first: 'الفصل الأول', second: 'الفصل الثاني', year: 'العام كاملاً' },
    add: 'إضافة مجموعة', added: 'أُضيفت المجموعة.', delete: 'حذف المجموعة', deleteConfirm: 'تُحذف المجموعة «{{name}}» وأيامها. متابعة؟', deleted: 'حُذفت المجموعة.',
    reset: 'إفراغ كل الأيام', resetConfirm: 'تُفرغ كل أيام المجموعة «{{name}}» التي لا حضور فيها. متابعة؟', resetDone: 'الأيام المُفرغة: {{n}}.',
    validate: 'اعتماد المجموعة', validated: 'معتمدة', validatedBy: 'اعتمدها {{name}} في {{at}}', unvalidate: 'اضغط لسحب الاعتماد', notValidated: 'غير معتمدة', validatedDone: 'اعتُمدت المجموعة.', unvalidatedDone: 'سُحب الاعتماد.',
    cancelled: 'ملغاة', renamed: 'حُفظ.', status: 'الحالة',
  },
  day: {
    n: 'اليوم {{n}}', extra: 'يوم إضافي', date: 'التاريخ', start: 'من', end: 'إلى', room: 'القاعة', trainer: 'المدرب', pickRoom: 'اختر قاعة', pickTrainer: 'اختر مدرباً', none: 'بدون',
    reset: 'إفراغ اليوم', locked: 'فيه حضور مسجل', closed: 'يوم مغلق للتدريب: {{kind}}', saved: 'حُفظ اليوم {{n}}.', search: 'ابحث…', taken: 'محجوزة: {{by}}', busy: 'مشغول في هذا الوقت', free: 'متاحة', noMatches: 'لا نتائج.', online: 'عن بُعد — بلا قاعة',
    capacity: 'المقاعد: {{n}}', checking: 'جارٍ فحص التوفر…',
  },
  kinds: { weekend: 'عطلة نهاية الأسبوع', off: 'إجازة', vacation: 'إجازة', exam: 'اختبارات', normal: 'دوام عادي' },
  settings: {
    title: 'إعدادات سابر+', supervisor: 'السماح بتعديل المشرف', supervisorHint: 'عند الإيقاف لا يتغير مشرف أي برنامج من سابر+.', delete: 'السماح بحذف المجموعات', deleteHint: 'عند التشغيل يحذف من يملك صلاحية إدارة المجموعات مجموعة بلا مسجلين.', saved: 'حُفظت الإعدادات.',
  },
  weekdays: ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'],
  plan: {
    open: 'خطة الأيام القادمة', title: 'خطة الأيام القادمة', hint: 'معاينة لا تُحفظ: حالة كل تاريخ — متاح، محجوز، أو إجازة. حدّد ما يناسب ثم طبّقه على المجموعة.',
    program: 'البرنامج', group: 'المجموعة', pickProgram: 'اختر البرنامج', pickGroup: 'اختر المجموعة', room: 'القاعة (اختياري)', trainer: 'المدرب (اختياري)', weekday: 'اليوم', every: 'التكرار', weekly: 'كل أسبوع', biweekly: 'كل أسبوعين',
    count: 'العدد (حد أقصى {{n}})', from: 'من تاريخ', preview: 'عرض الخطة', free: 'متاح', busy: 'محجوز', off: 'إجازة', weeklyOff: 'إجازة أسبوعية', eventsOk: 'مسموح بالفعاليات', chosen: 'المحددة: {{n}}',
    selectAll: 'تحديد المتاح', clearAll: 'إلغاء التحديد', apply: 'تطبيق على المجموعة', replace: 'في المجموعة {{saved}} يوماً محفوظاً، وستُستبدل بـ {{n}} يوماً من الخطة. لا يمكن التراجع. متابعة؟',
    empty: 'اختر المجموعة واليوم ثم اعرض الخطة.', noneChosen: 'حدّد تاريخاً واحداً على الأقل.', fromGroup: 'خطة الأيام',
  },
  cancel: {
    open: 'إلغاء البرامج', title: 'إلغاء البرامج والمجموعات', hint: 'عند الإلغاء تتحرر القاعة والمدرب في تواريخه، ويُبلَّغ المسجلون. إعادة التفعيل للمشرف العام فقط.',
    tabs: { program: 'إلغاء برنامج', group: 'إلغاء مجموعة', cancelled: 'الملغاة' }, search: 'ابحث باسم البرنامج…', reason: 'سبب الإلغاء', requestedBy: 'طلب الإلغاء بواسطة', requestedByHint: 'اسم الشخص أو الجهة',
    attachment: 'مرفق داعم (اختياري)', confirmProgram: 'تأكيد إلغاء البرنامج', confirmGroup: 'تأكيد إلغاء المجموعة', doneProgram: 'أُلغي البرنامج وتحررت قاعاته ومدربوه.', doneGroup: 'أُلغيت المجموعة وتحررت قاعتها ومدربها.',
    pick: 'اختر برنامجاً', pickGroup: 'اختر المجموعة', none: 'لا عناصر ملغاة.', reactivate: 'إعادة تفعيل', reactivated: 'أُعيد التفعيل.', superOnly: 'إعادة التفعيل للمشرف العام فقط', by: 'بواسطة {{name}}', requested: 'طلبه: {{name}}', file: 'المرفق',
    programs: 'برامج ملغاة', groups: 'مجموعات ملغاة', cancelGroup: 'إلغاء المجموعة', confirm: 'سيُلغى «{{name}}» ويُبلَّغ المسجلون. متابعة؟',
  },
  offday: {
    open: 'طلبات أيام الإجازة', title: 'طلبات الحجز في أيام الإجازة', hintApprover: 'راجع الطلبات واعتمدها أو ارفضها — يُفتح اليوم للجدولة فور الاعتماد.', hintMine: 'طلباتك وحالتها.',
    requestTitle: 'طلب حجز في يوم إجازة', requestHint: 'هذا اليوم مغلق للتدريب. أرسل طلباً بالسبب لإدارة المركز، أو اعتمده مباشرة إن كانت لديك الصلاحية.', reason: 'سبب الحجز في هذا اليوم', send: 'إرسال الطلب', instant: 'اعتماد فوري وفتح اليوم',
    sent: 'أُرسل الطلب، بانتظار موافقة إدارة المركز.', opened: 'اعتُمد اليوم وأصبح متاحاً للجدولة.', approve: 'اعتماد', reject: 'رفض', note: 'ملاحظة (اختياري)', none: 'لا طلبات.',
    status: { pending: 'قيد الاعتماد', approved: 'معتمد', rejected: 'مرفوض' }, by: 'من {{name}}', decidedBy: 'القرار: {{name}}', day: 'اليوم {{n}}', approved: 'اعتُمد الطلب.', rejected: 'رُفض الطلب.',
  },
  weekly: {
    open: 'الإجازات الأسبوعية', title: 'الإجازات الأسبوعية', hint: 'أيام إجازة تتكرر كل أسبوع إضافة إلى عطلة نهاية الأسبوع. تُغلق للتدريب، ويمكن السماح فيها بالفعاليات والاجتماعات.',
    weekend: 'عطلة نهاية الأسبوع (من إعدادات النظام)', label: 'الوصف', allowEvents: 'مسموح بالفعاليات', add: 'إضافة', saved: 'حُفظت الإجازة الأسبوعية.', removed: 'أُزيلت.', warn: 'تنبيه: جلسات قادمة تقع في هذا اليوم: {{n}}.', none: 'لا إجازات أسبوعية مضافة.',
  },
  details: {
    open: 'التفاصيل', title: 'تفاصيل البرنامج', tabs: { overview: 'نظرة عامة', groups: 'المجموعات', days: 'الأيام' }, summary: 'الوصف', objectives: 'الأهداف', audience: 'الفئة المستهدفة', category: 'التصنيف', method: 'طريقة التنفيذ',
    capacity: 'السعة', kits: 'الحقائب', registrations: 'المسجلون', cancelled: 'أُلغي في {{date}} — {{reason}}', noDays: 'لا أيام بعد.', status: 'الحالة', type: 'النوع', hours: 'الساعات',
  },
  survey: {
    title: 'رابط الاستبيان', open: 'فتح', copy: 'نسخ', copied: 'نُسخ الرابط.', qr: 'رمز QR', download: 'تنزيل PNG', edit: 'تعديل الروابط', link: 'رابط الاستبيان', editLink: 'رابط تعديل الاستمارة', none: 'لا رابط بعد', saved: 'حُفظت الروابط.',
  },
  pager: { prev: 'السابق', next: 'التالي', page: 'صفحة {{page}} من {{last}}' },
}

export const saberEn: typeof saberAr = {
  nav: 'SABER+',
  title: 'SABER+',
  subtitle: 'Every programme with its groups and training days on one screen: the date, room and trainer of each day, saved as you pick and checked against the calendar and bookings.',
  stats: { programs: 'Programmes this month', awaiting: 'Groups awaiting approval', rooms: 'Rooms booked this month', trainers: 'Available trainers' },
  filters: {
    search: 'Search by programme name or code', from: 'From', to: 'To', term: 'Term', first: 'First term', second: 'Second term', kit: 'Kit', ready: 'Ready', notReady: 'Not ready', clear: 'Clear filters',
  },
  view: { rows: 'Rows', cards: 'Cards', label: 'Days view' },
  tools: { title: 'Customise the toolbar', hint: 'Choose the tools shown; your choice is kept on this device.', plan: 'Plan the coming days', cancel: 'Cancel programmes', inbox: 'Closed-day requests', weekly: 'Weekly holidays', search: 'Search', dates: 'Date range', term: 'Term', kit: 'Kit readiness', view: 'View', expand: 'Expand all' },
  expandAll: 'Expand all', collapseAll: 'Collapse all',
  results: 'Programmes: {{n}}', empty: 'No programme matches the filters.', emptyHint: 'Change the search or clear the filters.',
  program: {
    hours: '{{n}} hours', days: 'Days per group: {{n}}', groups: 'Groups: {{n}}', noDates: 'No dates yet', supervisor: 'Supervisor', noSupervisor: 'No supervisor', supervisorLocked: 'Changing the supervisor is turned off in the settings',
    kitReady: 'Kit ready', kitNotReady: 'Kit not ready', kitAuto: 'Follows the linked kit', cancelled: 'Cancelled', open: 'Open the programme page', daysPerGroup: 'Days per group',
  },
  lights: { dates: 'Days with a date', rooms: 'Days with a room', trainers: 'Days with a trainer', of: '{{n}} of {{total}}' },
  group: {
    n: 'Group {{n}}', number: 'Group number', name: 'Group name', colour: 'Group colour', term: 'Term', termAuto: 'Automatic from the date', terms: { first: 'First term', second: 'Second term', year: 'Whole year' },
    add: 'Add a group', added: 'The group was added.', delete: 'Delete the group', deleteConfirm: 'The group “{{name}}” and its days will be deleted. Continue?', deleted: 'The group was deleted.',
    reset: 'Clear all days', resetConfirm: 'Every day of “{{name}}” without attendance will be cleared. Continue?', resetDone: '{{n}} days were cleared.',
    validate: 'Approve the group', validated: 'Approved', validatedBy: 'Approved by {{name}} on {{at}}', unvalidate: 'Press to withdraw the approval', notValidated: 'Not approved', validatedDone: 'The group was approved.', unvalidatedDone: 'The approval was withdrawn.',
    cancelled: 'Cancelled', renamed: 'Saved.', status: 'Status',
  },
  day: {
    n: 'Day {{n}}', extra: 'Extra day', date: 'Date', start: 'From', end: 'To', room: 'Room', trainer: 'Trainer', pickRoom: 'Choose a room', pickTrainer: 'Choose a trainer', none: 'None',
    reset: 'Clear the day', locked: 'Attendance recorded', closed: 'Closed for training: {{kind}}', saved: 'Day {{n}} saved.', search: 'Search…', taken: 'Booked: {{by}}', busy: 'Busy at this time', free: 'Free', noMatches: 'No results.', online: 'Online — no room',
    capacity: '{{n}} seats', checking: 'Checking availability…',
  },
  kinds: { weekend: 'Weekend', off: 'Holiday', vacation: 'Holiday', exam: 'Exams', normal: 'Normal working day' },
  settings: {
    title: 'SABER+ settings', supervisor: 'Allow changing the supervisor', supervisorHint: 'When off, no programme supervisor changes from SABER+.', delete: 'Allow deleting groups', deleteHint: 'When on, whoever manages groups can delete a group without registrations.', saved: 'Settings saved.',
  },
  weekdays: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
  plan: {
    open: 'Plan the coming days', title: 'Plan the coming days', hint: 'A preview, nothing is saved: each date is free, booked or a holiday. Select what suits and apply it to the group.',
    program: 'Programme', group: 'Group', pickProgram: 'Choose a programme', pickGroup: 'Choose a group', room: 'Room (optional)', trainer: 'Trainer (optional)', weekday: 'Day', every: 'Repeat', weekly: 'Every week', biweekly: 'Every two weeks',
    count: 'Count (max {{n}})', from: 'From', preview: 'Show the plan', free: 'Free', busy: 'Booked', off: 'Holiday', weeklyOff: 'Weekly holiday', eventsOk: 'Events allowed', chosen: 'Selected: {{n}}',
    selectAll: 'Select free', clearAll: 'Clear selection', apply: 'Apply to the group', replace: 'The group has {{saved}} saved days; they will be replaced by {{n}} days from the plan. This cannot be undone. Continue?',
    empty: 'Choose the group and the day, then show the plan.', noneChosen: 'Select at least one date.', fromGroup: 'Plan days',
  },
  cancel: {
    open: 'Cancel programmes', title: 'Cancel programmes and groups', hint: 'A cancellation frees the room and trainer on its dates and tells the registered people. Only the super administrator can reactivate.',
    tabs: { program: 'Cancel a programme', group: 'Cancel a group', cancelled: 'Cancelled' }, search: 'Search by programme name…', reason: 'Reason', requestedBy: 'Requested by', requestedByHint: 'Name of the person or body',
    attachment: 'Supporting document (optional)', confirmProgram: 'Cancel the programme', confirmGroup: 'Cancel the group', doneProgram: 'The programme was cancelled; its rooms and trainers are free.', doneGroup: 'The group was cancelled; its room and trainer are free.',
    pick: 'Choose a programme', pickGroup: 'Choose the group', none: 'Nothing is cancelled.', reactivate: 'Reactivate', reactivated: 'Reactivated.', superOnly: 'Only the super administrator can reactivate', by: 'by {{name}}', requested: 'Requested by {{name}}', file: 'Attachment',
    programs: 'Cancelled programmes', groups: 'Cancelled groups', cancelGroup: 'Cancel the group', confirm: '“{{name}}” will be cancelled and the registered people told. Continue?',
  },
  offday: {
    open: 'Closed-day requests', title: 'Requests to train on closed days', hintApprover: 'Review the requests and approve or reject them — the day opens for scheduling once approved.', hintMine: 'Your requests and their status.',
    requestTitle: 'Request to train on a closed day', requestHint: 'This day is closed for training. Send a request with the reason to the centre, or approve it now if you hold the right.', reason: 'Why train on this day', send: 'Send the request', instant: 'Approve now and open the day',
    sent: 'Request sent; waiting for the centre approval.', opened: 'Approved; the day is open for scheduling.', approve: 'Approve', reject: 'Reject', note: 'Note (optional)', none: 'No requests.',
    status: { pending: 'Pending', approved: 'Approved', rejected: 'Rejected' }, by: 'From {{name}}', decidedBy: 'Decided by {{name}}', day: 'Day {{n}}', approved: 'Request approved.', rejected: 'Request rejected.',
  },
  weekly: {
    open: 'Weekly holidays', title: 'Weekly holidays', hint: 'Days off every week on top of the weekend. They close training; events and meetings can still be allowed.',
    weekend: 'Weekend (system setting)', label: 'Label', allowEvents: 'Events allowed', add: 'Add', saved: 'Weekly holiday saved.', removed: 'Removed.', warn: 'Note: upcoming sessions on this day: {{n}}.', none: 'No weekly holidays added.',
  },
  details: {
    open: 'Details', title: 'Programme details', tabs: { overview: 'Overview', groups: 'Groups', days: 'Days' }, summary: 'Description', objectives: 'Objectives', audience: 'Audience', category: 'Category', method: 'Delivery',
    capacity: 'Capacity', kits: 'Kits', registrations: 'Registered', cancelled: 'Cancelled on {{date}} — {{reason}}', noDays: 'No days yet.', status: 'Status', type: 'Type', hours: 'Hours',
  },
  survey: {
    title: 'Survey link', open: 'Open', copy: 'Copy', copied: 'Link copied.', qr: 'QR code', download: 'Download PNG', edit: 'Edit links', link: 'Survey link', editLink: 'Form edit link', none: 'No link yet', saved: 'Links saved.',
  },
  pager: { prev: 'Previous', next: 'Next', page: 'Page {{page}} of {{last}}' },
}
