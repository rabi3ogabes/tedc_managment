/** Training groups, trainer assignment, the annual plan and internal workshops (Phase 02). */
export const structureAr = {
  groups: {
    tab: 'المجموعات', title: 'المجموعات التدريبية', hint: 'كل مجموعة لها مواعيدها ومقاعدها ومشرفها ومدربوها وحالتها.', new: 'مجموعة جديدة', empty: 'لا توجد مجموعات بعد.', seats: 'مقعد', sessions: 'جلسة',
    clone: 'نسخ', publish: 'نشر', unpublish: 'إلغاء النشر', changeStatus: 'تغيير الحالة', delete: 'حذف', confirmDelete: 'حذف المجموعة «{{name}}»؟', saved: 'تم الحفظ', deleted: 'تم الحذف',
    start: 'البداية', end: 'النهاية', capacity: 'السعة', mode: 'نمط التقديم', modes: { in_person: 'حضوري', online: 'عن بُعد', blended: 'مدمج', self_paced: 'ذاتي' },
    weekdays: 'أيام الجلسات', days: ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'], starts: 'من', ends: 'إلى', count: 'عدد الجلسات', emergency: 'مجموعة طارئة', emergencyReason: 'سبب الطوارئ',
    reason: 'السبب', postponedTo: 'التأجيل إلى', create: 'إنشاء المجموعة', cancel: 'إلغاء', save: 'حفظ', skipped: 'جلسات تخطّيناها: {{n}} (أيام عطلة أو امتحانات)', published: 'منشورة', draft: 'غير منشورة',
    status: { planned: 'مخطط لها', registration_open: 'التسجيل مفتوح', ongoing: 'جارية', incomplete: 'غير مكتملة', postponed: 'مؤجلة', cancelled: 'ملغاة', completed: 'مكتملة' },
    board: { title: 'لوحة حالة المجموعات', subtitle: 'تابع كل المجموعات التدريبية حسب حالتها. اسحب البطاقة إلى عمود آخر لتغيير الحالة.', all: 'الكل', year: 'السنة', emergencyOnly: 'الطارئة فقط', dropHere: 'أفلت هنا', table: 'جدول', kanban: 'لوحة', none: 'لا مجموعات', moveTitle: 'نقل إلى «{{status}}»', notAllowed: 'هذا الانتقال غير مسموح من هذه الحالة.', total: '{{n}} مجموعة' },
    trainers: { title: 'المدربون', propose: 'ترشيح مدرب', none: 'لا يوجد مدربون مرشحون.', role: 'الدور', lead: 'رئيسي', assistant: 'مساعد', hours: 'الساعات', pickTrainer: 'اختر المدرب', formWaiting: 'بانتظار نموذج المدرب', formDone: 'النموذج مكتمل', approve: 'اعتماد', reject: 'رفض', ref: 'رقم موافقة الجهة المختصة', refHint: 'يلزم رقم الموافقة الخارجية قبل الاعتماد.', note: 'ملاحظة', proposed: 'مرشح', approved: 'معتمد', rejected: 'مرفوض', decided: 'تم تسجيل القرار', proposedOk: 'تم ترشيح المدرب وإشعاره', conflicts: 'يوجد تعارض في جدول المدرب' },
    kit: { title: 'مطورو الحقيبة التدريبية', hint: 'اختر من يطوّر حقيبة البرنامج وحدّد الموعد النهائي.', due: 'الموعد النهائي', assign: 'إسناد', search: 'ابحث بالاسم أو البريد…', assigned: 'تم إسناد التطوير وإشعار الفريق' },
  },
  structure: {
    tab: 'الهيكل', tree: 'شجرة البرنامج', subs: 'البرامج الفرعية', newSub: 'برنامج فرعي', noSubs: 'لا توجد برامج فرعية.', rollup: 'الإجمالي: {{groups}} مجموعة · {{completed}} مكتملة · {{seats}} متدرب · {{hours}} ساعة', subHint: 'مستوى واحد فقط من البرامج الفرعية.',
    units: 'الوحدات والمحاور', unitsHint: 'قسّم البرنامج إلى وحدات بأهداف وساعات.', addUnit: 'إضافة وحدة', hours: 'الساعات', objectives: 'الأهداف (سطر لكل هدف)', save: 'حفظ الوحدات', saved: 'تم حفظ الهيكل', remove: 'حذف',
    titleAr: 'العنوان بالعربية', titleEn: 'العنوان بالإنجليزية', create: 'إنشاء', sub: 'فرعي',
  },
  assignments: { title: 'ترشيحاتي للتدريب', subtitle: 'عبّئ نموذج الإسناد ليتمكن المركز من اعتمادك.', empty: 'لا توجد ترشيحات حالياً.', availability: 'أؤكد تفرغي في كل المواعيد', cv: 'سيرتي الذاتية محدّثة', notes: 'ملاحظات', materials: 'المواد أو التجهيزات المطلوبة', submit: 'إرسال النموذج', submitted: 'تم إرسال النموذج', waiting: 'بانتظار قرار المركز' },
  plans: {
    nav: 'الخطة التدريبية', title: 'الخطة التدريبية السنوية', subtitle: 'ولّد الخطة من الاحتياجات المعتمدة، راجعها، اعتمدها، ثم تابع تنفيذها والانحرافات.', new: 'خطة جديدة', year: 'السنة', titleAr: 'العنوان بالعربية', titleEn: 'العنوان بالإنجليزية', create: 'إنشاء', empty: 'لا توجد خطط بعد.', version: 'إصدار',
    status: { draft: 'مسودة', in_review: 'قيد المراجعة', approved: 'معتمدة', active: 'قيد التنفيذ', closed: 'مغلقة' },
    tabs: { items: 'البنود', rules: 'القواعد', execution: 'التنفيذ', changes: 'سجل التغييرات' },
    generate: 'توليد من الاحتياجات', generated: 'أُضيف {{added}} بند ورُحّل {{carried}} بند', submit: 'إرسال للمراجعة', return: 'إعادة للتعديل', approve: 'اعتماد', activate: 'بدء التنفيذ', close: 'إغلاق', done: 'تم', exportXlsx: 'Excel', exportPdf: 'PDF',
    comment: 'ملاحظة المراجعة', reasonPrompt: 'سبب التعديل بعد الاعتماد', addItem: 'إضافة بند', itemTitleAr: 'العنوان بالعربية', itemTitleEn: 'العنوان بالإنجليزية',
    priority: { critical: 'حرجة', high: 'عالية', medium: 'متوسطة', low: 'منخفضة' }, score: 'الدرجة', groups: 'المجموعات', seats: 'المقاعد', hours: 'الساعات', window: 'النافذة', source: { needs: 'احتياجات', manual: 'يدوي', emergency: 'طارئ', carry_over: 'مرحّل' }, why: 'لماذا هذا البند؟',
    itemStatus: { planned: 'مخطط', in_execution: 'قيد التنفيذ', done: 'منفذ', postponed: 'مؤجل', cancelled: 'ملغى' }, remove: 'حذف البند', confirmRemove: 'حذف هذا البند؟',
    rules: { weights: 'أوزان الأولوية', severity: 'شدة الاحتياج', headcount: 'عدد المتدربين', breadth: 'اتساع المدارس', maxSeats: 'أقصى مقاعد للمجموعة', defaultHours: 'ساعات المجموعة الافتراضية', minFill: 'حد الامتلاء الأدنى %', carryOver: 'ترحيل البنود غير المكتملة', saved: 'تم حفظ القواعد', windows: 'ربع التنفيذ حسب الأولوية' },
    exec: { execution: 'نسبة التنفيذ', changed: 'التغيير بعد الاعتماد', emergency: 'الطارئ', deviations: 'الانحرافات', planned: 'المخطط', created: 'المنشأ', executed: 'المنفذ', none: 'لا انحرافات.', late: 'متأخر', under_filled: 'مقاعد غير ممتلئة', cancelled: 'ملغاة', postponed: 'مؤجلة', unplanned: 'خارج الخطة' },
    changeTypes: { added: 'إضافة', removed: 'حذف', modified: 'تعديل', postponed: 'تأجيل', cancelled: 'إلغاء' }, noChanges: 'لا تغييرات بعد الاعتماد.', baselineNote: 'بعد الاعتماد يُسجَّل كل تعديل مع سببه.',
  },
  workshops: {
    nav: 'الورش الداخلية', title: 'الورش الداخلية للمدارس', subtitle: 'تقترح المدرسة ورشتها، ويعتمدها المركز، ثم تسجّل المدرسة منسوبيها وتنفذها.', new: 'ورشة جديدة', empty: 'لا توجد ورش.', hours: 'الساعات', capacity: 'السعة', start: 'البداية', end: 'النهاية', objectives: 'الأهداف (سطر لكل هدف)',
    approval: { pending: 'بانتظار الاعتماد', approved: 'معتمدة', rejected: 'مرفوضة' }, approve: 'اعتماد', reject: 'رفض', note: 'سبب الرفض', submit: 'إرسال للاعتماد', submitted: 'تم إرسال الورشة للمركز', decided: 'تم تسجيل القرار',
    register: 'تسجيل المنسوبين', registerHint: 'ألصق الأرقام الوظيفية لمنسوبي مدرستك، رقماً في كل سطر.', registered: 'سُجّل {{n}} وتخطّينا {{m}}', school: 'المدرسة', registrations: 'مسجلون',
  },
}

export const structureEn: typeof structureAr = {
  groups: {
    tab: 'Groups', title: 'Training groups', hint: 'Each group has its own dates, seats, supervisor, trainers and status.', new: 'New group', empty: 'No groups yet.', seats: 'seats', sessions: 'sessions',
    clone: 'Clone', publish: 'Publish', unpublish: 'Unpublish', changeStatus: 'Change status', delete: 'Delete', confirmDelete: 'Delete group “{{name}}”?', saved: 'Saved', deleted: 'Deleted',
    start: 'Start', end: 'End', capacity: 'Capacity', mode: 'Delivery', modes: { in_person: 'In person', online: 'Online', blended: 'Blended', self_paced: 'Self-paced' },
    weekdays: 'Session days', days: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], starts: 'From', ends: 'To', count: 'Number of sessions', emergency: 'Emergency group', emergencyReason: 'Why is it an emergency?',
    reason: 'Reason', postponedTo: 'Postponed to', create: 'Create group', cancel: 'Cancel', save: 'Save', skipped: 'Sessions skipped: {{n}} (days off or exams)', published: 'Published', draft: 'Not published',
    status: { planned: 'Planned', registration_open: 'Registration open', ongoing: 'Ongoing', incomplete: 'Incomplete', postponed: 'Postponed', cancelled: 'Cancelled', completed: 'Completed' },
    board: { title: 'Group status board', subtitle: 'Follow every training group by status. Drag a card to another column to change its status.', all: 'All', year: 'Year', emergencyOnly: 'Emergency only', dropHere: 'Drop here', table: 'Table', kanban: 'Board', none: 'No groups', moveTitle: 'Move to “{{status}}”', notAllowed: 'That move is not allowed from this status.', total: '{{n}} groups' },
    trainers: { title: 'Trainers', propose: 'Propose a trainer', none: 'No trainers proposed.', role: 'Role', lead: 'Lead', assistant: 'Assistant', hours: 'Hours', pickTrainer: 'Choose a trainer', formWaiting: 'Waiting for the trainer’s form', formDone: 'Form completed', approve: 'Approve', reject: 'Reject', ref: 'Competent authority approval reference', refHint: 'The external approval reference is required before approving.', note: 'Note', proposed: 'Proposed', approved: 'Approved', rejected: 'Rejected', decided: 'Decision recorded', proposedOk: 'The trainer was proposed and notified', conflicts: 'The trainer has a schedule conflict' },
    kit: { title: 'Kit developers', hint: 'Choose who develops the program’s kit and set the due date.', due: 'Due date', assign: 'Assign', search: 'Search by name or email…', assigned: 'Development assigned and the team notified' },
  },
  structure: {
    tab: 'Structure', tree: 'Program tree', subs: 'Sub-programs', newSub: 'New sub-program', noSubs: 'No sub-programs.', rollup: 'Total: {{groups}} group(s) · {{completed}} completed · {{seats}} trainees · {{hours}} h', subHint: 'Only one level of sub-programs is allowed.',
    units: 'Units and axes', unitsHint: 'Split the program into units with objectives and hours.', addUnit: 'Add a unit', hours: 'Hours', objectives: 'Objectives (one per line)', save: 'Save units', saved: 'Structure saved', remove: 'Remove',
    titleAr: 'Arabic title', titleEn: 'English title', create: 'Create', sub: 'Sub',
  },
  assignments: { title: 'My training proposals', subtitle: 'Fill in the assignment form so the centre can approve you.', empty: 'No proposals right now.', availability: 'I confirm I am free for all the dates', cv: 'My CV is up to date', notes: 'Notes', materials: 'Materials or equipment needed', submit: 'Submit the form', submitted: 'Form submitted', waiting: 'Waiting for the centre’s decision' },
  plans: {
    nav: 'Training plan', title: 'Annual training plan', subtitle: 'Generate the plan from approved needs, review it, approve it, then follow its execution and deviations.', new: 'New plan', year: 'Year', titleAr: 'Arabic title', titleEn: 'English title', create: 'Create', empty: 'No plans yet.', version: 'Version',
    status: { draft: 'Draft', in_review: 'In review', approved: 'Approved', active: 'Executing', closed: 'Closed' },
    tabs: { items: 'Items', rules: 'Rules', execution: 'Execution', changes: 'Change log' },
    generate: 'Generate from needs', generated: '{{added}} item(s) added, {{carried}} carried over', submit: 'Submit for review', return: 'Return for changes', approve: 'Approve', activate: 'Start execution', close: 'Close', done: 'Done', exportXlsx: 'Excel', exportPdf: 'PDF',
    comment: 'Review comment', reasonPrompt: 'Reason for the change after approval', addItem: 'Add an item', itemTitleAr: 'Arabic title', itemTitleEn: 'English title',
    priority: { critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low' }, score: 'Score', groups: 'Groups', seats: 'Seats', hours: 'Hours', window: 'Window', source: { needs: 'Needs', manual: 'Manual', emergency: 'Emergency', carry_over: 'Carried over' }, why: 'Why this item?',
    itemStatus: { planned: 'Planned', in_execution: 'Executing', done: 'Done', postponed: 'Postponed', cancelled: 'Cancelled' }, remove: 'Remove item', confirmRemove: 'Remove this item?',
    rules: { weights: 'Priority weights', severity: 'Need severity', headcount: 'Head count', breadth: 'School breadth', maxSeats: 'Max seats per group', defaultHours: 'Default hours per group', minFill: 'Minimum fill %', carryOver: 'Carry over unfinished items', saved: 'Rules saved', windows: 'Execution quarter by priority' },
    exec: { execution: 'Execution', changed: 'Changed after approval', emergency: 'Emergency', deviations: 'Deviations', planned: 'Planned', created: 'Created', executed: 'Executed', none: 'No deviations.', late: 'Late', under_filled: 'Under-filled', cancelled: 'Cancelled', postponed: 'Postponed', unplanned: 'Outside the plan' },
    changeTypes: { added: 'Added', removed: 'Removed', modified: 'Modified', postponed: 'Postponed', cancelled: 'Cancelled' }, noChanges: 'No changes since approval.', baselineNote: 'After approval every change is logged with its reason.',
  },
  workshops: {
    nav: 'Internal workshops', title: 'School internal workshops', subtitle: 'The school proposes its workshop, the centre approves it, then the school registers its staff and runs it.', new: 'New workshop', empty: 'No workshops.', hours: 'Hours', capacity: 'Capacity', start: 'Start', end: 'End', objectives: 'Objectives (one per line)',
    approval: { pending: 'Awaiting approval', approved: 'Approved', rejected: 'Rejected' }, approve: 'Approve', reject: 'Reject', note: 'Reason for rejecting', submit: 'Submit for approval', submitted: 'The workshop was sent to the centre', decided: 'Decision recorded',
    register: 'Register staff', registerHint: 'Paste your school staff employee numbers, one per line.', registered: '{{n}} registered, {{m}} skipped', school: 'School', registrations: 'Registered',
  },
}
