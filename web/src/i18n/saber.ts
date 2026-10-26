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
  tools: { title: 'تخصيص شريط الأدوات', hint: 'اختر الأدوات الظاهرة؛ يُحفظ اختيارك على هذا الجهاز.', search: 'البحث', dates: 'نطاق التاريخ', term: 'الفصل', kit: 'جاهزية الحقيبة', view: 'طريقة العرض', expand: 'توسيع الكل' },
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
  tools: { title: 'Customise the toolbar', hint: 'Choose the tools shown; your choice is kept on this device.', search: 'Search', dates: 'Date range', term: 'Term', kit: 'Kit readiness', view: 'View', expand: 'Expand all' },
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
  pager: { prev: 'Previous', next: 'Next', page: 'Page {{page}} of {{last}}' },
}
