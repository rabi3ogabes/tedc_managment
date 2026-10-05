/** Roles, scopes, switching, school groups, program grants and global search (Phase 01). */
export const accessAr = {
  roles: { switchTitle: 'العمل بدور', switched: 'تم التبديل إلى دور «{{role}}»' },
  search: {
    title: 'البحث الشامل', button: 'ابحث…', placeholder: 'ابحث عن برنامج أو شخص أو مدرب أو حقيبة أو شهادة أو وظيفة…', clear: 'مسح', recent: 'عمليات البحث الأخيرة',
    hint: 'اكتب حرفين على الأقل. يظهر لك ما تسمح به صلاحيات دورك الحالي فقط.', none: 'لا نتائج لـ «{{q}}»', suggest: 'جرّب كلمة أقصر أو رقم البرنامج أو اسماً آخر.', searching: 'جارٍ البحث…',
    keys: '↑ ↓ للتنقل · Enter للفتح', groups: { functions: 'الوظائف', programs: 'البرامج', people: 'الأشخاص', trainers: 'المدربون', kits: 'الحقائب', certificates: 'الشهادات', news: 'الأخبار' },
  },
  schoolPicker: { search: 'ابحث باسم المدرسة أو رمزها…', loading: 'جارٍ التحميل…', none: 'لا توجد مدارس مطابقة' },
  rolesAdmin: {
    title: 'الأدوار والصلاحيات', subtitle: 'كل دور يحدد ما يراه المستخدم وما يفعله. أنشئ دوراً مخصصاً أو انسخ دوراً موجوداً ثم عدّل صلاحياته.', newRole: 'دور جديد', users: 'مستخدم', permissions: 'صلاحية', custom: 'مخصص',
    pick: 'اختر دوراً من القائمة.', scopes: 'النطاقات المسموحة', scope: { ministry: 'الوزارة', school_group: 'مجموعة مدارس', school: 'مدرسة', department: 'قسم' }, delete: 'حذف الدور', review: 'مراجعة التغييرات',
    superNote: 'مدير النظام يملك كل الصلاحيات تلقائياً ولا تُعدَّل صلاحياته.', systemNote: 'هذا دور من أدوار النظام: يمكن تعديل صلاحياته، ولا يمكن حذفه. لتخصيص دور كامل أنشئ نسخة منه.',
    search: 'ابحث في الصلاحيات…', selectAll: 'تحديد الكل', saved: 'تم حفظ الصلاحيات', deleted: 'تم حذف الدور', created: 'تم إنشاء الدور', confirmDelete: 'حذف الدور «{{name}}»؟',
    diffTitle: 'معاينة التغييرات', diffIntro: 'ستتغير صلاحيات الدور «{{name}}» كما يلي:', added: 'تُضاف', removed: 'تُزال', confirmSave: 'تأكيد الحفظ', cancel: 'إلغاء',
    nameAr: 'الاسم بالعربية', nameEn: 'الاسم بالإنجليزية', cloneFrom: 'البدء من', cloneHint: 'عند النسخ تنتقل كل صلاحيات الدور المختار إلى الدور الجديد.', fromScratch: 'دور فارغ', landing: 'صفحة البداية', clone: 'نسخ وإنشاء', create: 'إنشاء',
    groups: { dashboard: 'لوحة التحكم', analytics: 'التحليلات', organization: 'المؤسسة', programs: 'البرامج', registrations: 'التسجيلات', attendance: 'الحضور', tasks: 'المهام', certificates: 'الشهادات', impact: 'الأثر', needs: 'الاحتياجات', communication: 'التواصل', ai: 'الذكاء الاصطناعي', reports: 'التقارير', security: 'الأمان', kits: 'الحقائب', settings: 'الإعدادات', planning: 'التخطيط', general: 'عام' },
  },
  userRoles: {
    manage: 'الأدوار', title: 'أدوار {{name}}', add: 'منح دور', role: 'الدور', scope: 'النطاق', expires: 'ينتهي في (اختياري)', expiresHint: 'بعد هذا التاريخ يتوقف الدور تلقائياً.', grant: 'منح الدور', granted: 'تم منح الدور', revoked: 'تم سحب الدور',
    revoke: 'سحب الدور', confirmRevoke: 'سحب الدور «{{role}}»؟', expired: 'منتهي', until: 'حتى {{date}}',
  },
  schoolGroups: {
    title: 'مجموعات المدارس', subtitle: 'اجمع المدارس في مديريات أو عناقيد أو مراحل، ثم امنح الأدوار عليها.', new: 'مجموعة جديدة', import: 'استيراد CSV', empty: 'لا توجد مجموعات بعد. أنشئ مجموعة أو استورد ملف CSV.',
    schools: 'مدرسة', edit: 'تعديل', delete: 'حذف', save: 'حفظ', cancel: 'إلغاء', saved: 'تم الحفظ', deleted: 'تم الحذف', confirmDelete: 'حذف المجموعة «{{name}}»؟',
    nameAr: 'الاسم بالعربية', nameEn: 'الاسم بالإنجليزية', code: 'الرمز', type: 'النوع', schoolsOf: 'مدارس المجموعة', types: { directorate: 'مديرية', cluster: 'عنقود', stage: 'مرحلة', custom: 'مخصص' },
    report: 'نتيجة الاستيراد', reportSummary: 'أُنشئت {{created}} مجموعة وحُدّثت {{updated}} وربطت {{linked}} مدرسة.', unknown: 'رموز مدارس غير معروفة ({{n}})',
  },
  grants: {
    tab: 'الفريق والصلاحيات', title: 'صلاحيات البرنامج', hint: 'امنح شخصاً حق تسجيل الحضور أو إرسال الإشعارات أو مراجعة المهام أو إسناد الحقائب لهذا البرنامج وحده.', person: 'الشخص', search: 'ابحث بالاسم أو البريد…', noPeople: 'لا يوجد أشخاص مطابقون',
    ability: 'الصلاحية', expires: 'تنتهي في', grant: 'منح', added: 'تم منح الصلاحية', revoked: 'تم سحب الصلاحية', revoke: 'سحب', confirmRevoke: 'سحب الصلاحية من {{name}}؟', empty: 'لم تُمنح صلاحيات خاصة لهذا البرنامج بعد.',
    expired: 'منتهية', until: 'حتى {{date}}', noExpiry: 'بلا انتهاء', abilities: { 'attendance.mark': 'تسجيل الحضور', 'notifications.send': 'إرسال الإشعارات', 'tasks.review': 'مراجعة المهام', 'kits.assign': 'إسناد الحقائب' },
  },
}

export const accessEn: typeof accessAr = {
  roles: { switchTitle: 'Work as', switched: 'Switched to “{{role}}”' },
  search: {
    title: 'Global search', button: 'Search…', placeholder: 'Search programs, people, trainers, kits, certificates or functions…', clear: 'Clear', recent: 'Recent searches',
    hint: 'Type at least two characters. You see only what your current role allows.', none: 'No results for “{{q}}”', suggest: 'Try a shorter word, a program code or another name.', searching: 'Searching…',
    keys: '↑ ↓ to move · Enter to open', groups: { functions: 'Functions', programs: 'Programs', people: 'People', trainers: 'Trainers', kits: 'Kits', certificates: 'Certificates', news: 'News' },
  },
  schoolPicker: { search: 'Search by school name or code…', loading: 'Loading…', none: 'No matching schools' },
  rolesAdmin: {
    title: 'Roles & permissions', subtitle: 'Each role decides what a user sees and does. Create a custom role, or copy an existing one and adjust its permissions.', newRole: 'New role', users: 'users', permissions: 'permissions', custom: 'Custom',
    pick: 'Pick a role from the list.', scopes: 'Allowed scopes', scope: { ministry: 'Ministry', school_group: 'School group', school: 'School', department: 'Department' }, delete: 'Delete role', review: 'Review changes',
    superNote: 'The super administrator has every permission automatically; it cannot be edited.', systemNote: 'This is a system role: its permissions can be edited but it cannot be deleted. To customise a role completely, make a copy.',
    search: 'Search permissions…', selectAll: 'Select all', saved: 'Permissions saved', deleted: 'Role deleted', created: 'Role created', confirmDelete: 'Delete the role “{{name}}”?',
    diffTitle: 'Preview of the changes', diffIntro: 'The permissions of “{{name}}” will change as follows:', added: 'Added', removed: 'Removed', confirmSave: 'Confirm and save', cancel: 'Cancel',
    nameAr: 'Name in Arabic', nameEn: 'Name in English', cloneFrom: 'Start from', cloneHint: 'When you copy a role, all its permissions move to the new role.', fromScratch: 'An empty role', landing: 'Landing page', clone: 'Copy and create', create: 'Create',
    groups: { dashboard: 'Dashboard', analytics: 'Analytics', organization: 'Organization', programs: 'Programs', registrations: 'Registrations', attendance: 'Attendance', tasks: 'Tasks', certificates: 'Certificates', impact: 'Impact', needs: 'Needs', communication: 'Communication', ai: 'Artificial intelligence', reports: 'Reports', security: 'Security', kits: 'Kits', settings: 'Settings', planning: 'Planning', general: 'General' },
  },
  userRoles: {
    manage: 'Roles', title: 'Roles of {{name}}', add: 'Grant a role', role: 'Role', scope: 'Scope', expires: 'Ends on (optional)', expiresHint: 'After this date the role stops by itself.', grant: 'Grant role', granted: 'Role granted', revoked: 'Role removed',
    revoke: 'Remove role', confirmRevoke: 'Remove the role “{{role}}”?', expired: 'Expired', until: 'Until {{date}}',
  },
  schoolGroups: {
    title: 'School groups', subtitle: 'Gather schools into directorates, clusters or stages, then grant roles over them.', new: 'New group', import: 'Import CSV', empty: 'No groups yet. Create one or import a CSV file.',
    schools: 'schools', edit: 'Edit', delete: 'Delete', save: 'Save', cancel: 'Cancel', saved: 'Saved', deleted: 'Deleted', confirmDelete: 'Delete the group “{{name}}”?',
    nameAr: 'Name in Arabic', nameEn: 'Name in English', code: 'Code', type: 'Type', schoolsOf: 'Schools of the group', types: { directorate: 'Directorate', cluster: 'Cluster', stage: 'Stage', custom: 'Custom' },
    report: 'Import result', reportSummary: '{{created}} groups created, {{updated}} updated and {{linked}} schools linked.', unknown: 'Unknown school codes ({{n}})',
  },
  grants: {
    tab: 'Staff & grants', title: 'Program rights', hint: 'Give a person the right to mark attendance, send notifications, review tasks or assign kits for this program only.', person: 'Person', search: 'Search by name or e-mail…', noPeople: 'No matching people',
    ability: 'Right', expires: 'Ends on', grant: 'Grant', added: 'Right granted', revoked: 'Right removed', revoke: 'Remove', confirmRevoke: 'Remove the right from {{name}}?', empty: 'No special rights have been granted for this program yet.',
    expired: 'Expired', until: 'Until {{date}}', noExpiry: 'No end date', abilities: { 'attendance.mark': 'Mark attendance', 'notifications.send': 'Send notifications', 'tasks.review': 'Review tasks', 'kits.assign': 'Assign kits' },
  },
}
