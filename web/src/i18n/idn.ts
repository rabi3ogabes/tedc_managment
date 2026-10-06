/** Sign-in security, integrations hub, data migration, Teams and "report a problem" (Phase 13). */
export const idnAr = {
  idn: {
    navSecurity: 'أمان الحساب', navSecurityAdmin: 'سياسة الأمان والجلسات', navIntegrations: 'التكاملات', navMigration: 'ترحيل البيانات', navReport: 'الإبلاغ عن مشكلة',
    login: {
      sso: 'الدخول بحساب الوزارة', or: 'أو', local: 'الدخول بكلمة المرور', directory: 'حساب الدليل (AD)', username: 'اسم المستخدم', ssoError: 'تعذّر الدخول الموحد. حاول مرة أخرى أو تواصل مع المركز.', completing: 'جارٍ إكمال الدخول…',
      mfaTitle: 'التحقق بخطوتين', mfaHint: 'أدخل الرمز لإكمال الدخول.', methods: { totp: 'تطبيق المصادقة', email: 'بريد إلكتروني', sms: 'رسالة نصية', recovery: 'رمز استرداد' }, send: 'إرسال الرمز', sent: 'أُرسل الرمز.', code: 'الرمز', verify: 'تحقق', remember: 'تذكّر هذا الجهاز', back: 'رجوع',
      setup: 'إعداد تطبيق المصادقة', setupHint: 'امسح الرمز أو أدخل المفتاح في تطبيق المصادقة ثم اكتب الرمز الذي يظهر.', secret: 'المفتاح', openApp: 'فتح التطبيق', confirm: 'تأكيد', recoveryTitle: 'احتفظ برموز الاسترداد', recoveryHint: 'يعمل كل رمز مرة واحدة إذا فقدت هاتفك. لن تُعرض مرة أخرى.', continue: 'متابعة',
    },
    acct: {
      title: 'أمان الحساب', password: 'كلمة المرور', current: 'كلمة المرور الحالية', next: 'كلمة المرور الجديدة', confirm: 'تأكيد كلمة المرور', change: 'تغيير كلمة المرور', changed: 'تم تغيير كلمة المرور وإنهاء الجلسات الأخرى.', expired: 'انتهت صلاحية كلمة المرور؛ اخترْ كلمة جديدة للمتابعة.',
      policy: 'الشروط: {{min}} خانات على الأقل', upper: 'حرف كبير', lower: 'حرف صغير', digit: 'رقم', symbol: 'رمز',
      mfa: 'التحقق بخطوتين', mfaOn: 'مفعّل', mfaOff: 'غير مفعّل', mfaRequired: 'يتطلبه دورك', enable: 'تفعيل', disable: 'إيقاف', newCodes: 'رموز استرداد جديدة', codesLeft: 'رموز الاسترداد المتبقية: {{n}}',
      sessions: 'جلساتي', thisOne: 'هذه الجلسة', end: 'إنهاء', since: 'منذ', lastSeen: 'آخر نشاط', ended: 'تم إنهاء الجلسة.',
    },
    sec: {
      title: 'سياسة الأمان', password: 'كلمة المرور', minLength: 'الحد الأدنى للطول', upper: 'حرف كبير', lower: 'حرف صغير', digit: 'رقم', symbol: 'رمز', history: 'عدد كلمات المرور السابقة المحظورة', expiry: 'مدة الصلاحية (أيام، 0 = بلا انتهاء)', breached: 'فحص التسريبات المعروفة',
      lockout: 'القفل', attempts: 'عدد المحاولات الخاطئة', lockMinutes: 'مدة القفل (دقائق)', mfa: 'التحقق بخطوتين', enforce: 'إلزامي للأدوار', methods: 'الطرق المتاحة', remember: 'تذكّر الجهاز (أيام)',
      sessions: 'الجلسات', idle: 'الخمول (دقائق)', absolute: 'أقصى مدة للجلسة (ساعات)', save: 'حفظ السياسة', saved: 'تم حفظ السياسة.', stepUp: 'أكّد رمز التحقق لمتابعة هذا الإجراء.', stepCode: 'رمز التحقق',
      active: 'الجلسات النشطة', user: 'المستخدم', device: 'الجهاز', terminate: 'إنهاء', terminateAll: 'إنهاء كل جلساته', unlock: 'فك القفل', resetMfa: 'إعادة ضبط التحقق بخطوتين', done: 'تم.', search: 'ابحث بالاسم أو البريد',
    },
    hub: {
      title: 'مركز التكاملات', subtitle: 'الأنظمة المتصلة بالمنصة وحالتها وسجلاتها.', tabs: { systems: 'الأنظمة', webhooks: 'الأحداث والـ Webhooks' }, groups: { identity: 'الهوية والدخول', collaboration: 'التعاون', ministry: 'أنظمة الوزارة' },
      health: { ok: 'يعمل', degraded: 'بطيء', down: 'متوقف', unknown: 'غير معروف' }, driver: 'نوع الربط', enabled: 'مفعّل', check: 'فحص الاتصال', sync: 'مزامنة الآن', logs: 'السجل', settings: 'الإعدادات', last: 'آخر مزامنة', calls: 'اتصالات ٢٤ ساعة', errors: 'أخطاء', paused: 'الاتصال متوقف مؤقتًا بعد إخفاقات متتالية', secretSet: 'محفوظ — اتركه فارغًا للإبقاء عليه', save: 'حفظ', saved: 'تم الحفظ.', elsewhere: 'يُدار من: {{where}}', synced: 'اكتملت المزامنة.',
      fake: 'تجريبي (بيانات وهمية)', http: 'HTTP', oidc: 'OpenID Connect', ldap: 'LDAP', graph: 'Microsoft Graph', direction: { in: 'وارد', out: 'صادر' }, time: 'الوقت', operation: 'العملية', status: 'الحالة', duration: 'المدة', error: 'الخطأ',
      wh: { new: 'اشتراك جديد', name: 'الاسم', url: 'عنوان الاستقبال (HTTPS)', events: 'الأحداث', secret: 'السر (يُعرض مرة واحدة)', test: 'اختبار', rotate: 'تجديد السر', deliveries: 'سجل التسليم', replay: 'إعادة الإرسال', pending: 'منتظر', dead: 'فاشل', delivered: 'سُلّم', empty: 'لا توجد اشتراكات.', copy: 'انسخ السر الآن؛ لن يظهر مرة أخرى.' },
    },
    mig: {
      title: 'ترحيل البيانات', subtitle: 'استيراد بيانات النظام القديم: تحقق وتنظيف ثم تجربة ثم استيراد، مع تسوية وإمكانية التراجع.', kind: 'نوع البيانات', kinds: { employees: 'الموظفون', trainers: 'المدربون', programs: 'البرامج', registrations: 'التسجيلات', attendance: 'ملخص الحضور', certificates: 'الشهادات', pd: 'التطوير المهني' },
      order: 'استورد بهذا الترتيب: الموظفون والمدربون والبرامج أولًا ثم ما يشير إليها.', template: 'تنزيل القالب', upload: 'رفع ملف (CSV أو Excel)', steps: { upload: 'رفع', map: 'مطابقة الأعمدة', validate: 'تحقق', dry: 'تجربة', import: 'استيراد' },
      mapping: 'مطابقة الأعمدة', source: 'عمود الملف', target: 'الحقل', ignore: 'تجاهل', defaults: 'قيم افتراضية', valueMaps: 'تحويل القيم', valueMapHint: 'مثال: «معلمة» ← TEACHER', save: 'حفظ المطابقة', validateBtn: 'تحقق من الصفوف', dryBtn: 'تجربة بلا حفظ', importBtn: 'استيراد', rollbackBtn: 'التراجع عن الدفعة',
      total: 'الصفوف', valid: 'سليمة', invalid: 'بها أخطاء', duplicate: 'مكررة', willCreate: 'ستُنشأ', willUpdate: 'ستُحدَّث', problems: 'أكثر المشكلات', errorsCsv: 'تنزيل الأخطاء', rows: 'الصفوف', recon: 'التسوية', matches: 'الأرقام والمفاتيح مطابقة', mismatch: 'غير مطابقة — راجع التقرير', confirmImport: 'استيراد الصفوف السليمة الآن؟', confirmRollback: 'سيُلغى كل ما أضافته هذه الدفعة. متأكد؟', batches: 'الدفعات السابقة', status: 'الحالة',
      retention: 'تُحذف البيانات المرفوعة تلقائيًا بعد ١٤ يومًا.', stepUp: 'يتطلب تأكيد التحقق بخطوتين.',
    },
    teams: { title: 'Microsoft Teams', create: 'إنشاء اجتماع', join: 'رابط الاجتماع', cancel: 'إلغاء الاجتماع', pull: 'سحب الحضور الآن', notReady: 'التكامل غير مُعدّ.', attendance: 'الحضور حسب مدة المشاركة', minutes: 'الدقائق', percent: 'النسبة', matched: 'مطابق', unmatched: 'غير مطابق', synced: 'آخر سحب', team: 'فريق المجموعة', syncMembers: 'مزامنة الأعضاء والملفات', open: 'فتح الفريق' },
    ticket: { title: 'الإبلاغ عن مشكلة', category: 'النوع', priority: 'الأهمية', subject: 'العنوان', description: 'صف المشكلة', screenshot: 'لقطة شاشة (اختياري)', send: 'إرسال البلاغ', sent: 'تم استلام بلاغك. رقم التذكرة: {{no}}', queued: 'سُجّل بلاغك وسيُرسل قريبًا.', page: 'الصفحة: {{url}}', cats: { bug: 'خلل', access: 'صلاحيات ودخول', data: 'بيانات خاطئة', request: 'طلب', other: 'أخرى' }, prios: { low: 'منخفضة', normal: 'عادية', high: 'عالية', urgent: 'عاجلة' }, mine: 'بلاغاتي' },
  },
}

export const idnEn = {
  idn: {
    navSecurity: 'Account security', navSecurityAdmin: 'Security policy and sessions', navIntegrations: 'Integrations', navMigration: 'Data migration', navReport: 'Report a problem',
    login: {
      sso: 'Sign in with Ministry account', or: 'or', local: 'Sign in with a password', directory: 'Directory account (AD)', username: 'Username', ssoError: 'Single sign-on failed. Try again or contact the training centre.', completing: 'Completing sign-in…',
      mfaTitle: 'Two-step verification', mfaHint: 'Enter the code to finish signing in.', methods: { totp: 'Authenticator app', email: 'E-mail', sms: 'SMS', recovery: 'Recovery code' }, send: 'Send code', sent: 'Code sent.', code: 'Code', verify: 'Verify', remember: 'Remember this device', back: 'Back',
      setup: 'Set up an authenticator app', setupHint: 'Scan the code or enter the key in your authenticator app, then type the code it shows.', secret: 'Key', openApp: 'Open app', confirm: 'Confirm', recoveryTitle: 'Keep your recovery codes', recoveryHint: 'Each code works once if you lose your phone. They are not shown again.', continue: 'Continue',
    },
    acct: {
      title: 'Account security', password: 'Password', current: 'Current password', next: 'New password', confirm: 'Confirm password', change: 'Change password', changed: 'Password changed; other sessions were ended.', expired: 'Your password has expired; choose a new one to continue.',
      policy: 'Requirements: at least {{min}} characters', upper: 'uppercase letter', lower: 'lowercase letter', digit: 'digit', symbol: 'symbol',
      mfa: 'Two-step verification', mfaOn: 'On', mfaOff: 'Off', mfaRequired: 'Required for your role', enable: 'Turn on', disable: 'Turn off', newCodes: 'New recovery codes', codesLeft: 'Recovery codes left: {{n}}',
      sessions: 'My sessions', thisOne: 'This session', end: 'End', since: 'Since', lastSeen: 'Last active', ended: 'Session ended.',
    },
    sec: {
      title: 'Security policy', password: 'Password', minLength: 'Minimum length', upper: 'Uppercase letter', lower: 'Lowercase letter', digit: 'Digit', symbol: 'Symbol', history: 'Previous passwords blocked', expiry: 'Expiry (days, 0 = never)', breached: 'Check against known breaches',
      lockout: 'Lockout', attempts: 'Wrong attempts allowed', lockMinutes: 'Lock for (minutes)', mfa: 'Two-step verification', enforce: 'Required for roles', methods: 'Allowed methods', remember: 'Remember device (days)',
      sessions: 'Sessions', idle: 'Idle timeout (minutes)', absolute: 'Maximum session length (hours)', save: 'Save policy', saved: 'Policy saved.', stepUp: 'Confirm your verification code to continue with this action.', stepCode: 'Verification code',
      active: 'Active sessions', user: 'User', device: 'Device', terminate: 'End', terminateAll: 'End all their sessions', unlock: 'Unlock', resetMfa: 'Reset two-step verification', done: 'Done.', search: 'Search by name or e-mail',
    },
    hub: {
      title: 'Integration hub', subtitle: 'The systems connected to the platform, their health and logs.', tabs: { systems: 'Systems', webhooks: 'Events and webhooks' }, groups: { identity: 'Identity and sign-in', collaboration: 'Collaboration', ministry: 'Ministry systems' },
      health: { ok: 'Healthy', degraded: 'Slow', down: 'Down', unknown: 'Unknown' }, driver: 'Connection type', enabled: 'On', check: 'Test connection', sync: 'Sync now', logs: 'Log', settings: 'Settings', last: 'Last sync', calls: 'Calls (24 h)', errors: 'errors', paused: 'Calls are paused after repeated failures', secretSet: 'Saved — leave empty to keep it', save: 'Save', saved: 'Saved.', elsewhere: 'Managed in: {{where}}', synced: 'Sync complete.',
      fake: 'Training (fake data)', http: 'HTTP', oidc: 'OpenID Connect', ldap: 'LDAP', graph: 'Microsoft Graph', direction: { in: 'Incoming', out: 'Outgoing' }, time: 'Time', operation: 'Operation', status: 'Status', duration: 'Duration', error: 'Error',
      wh: { new: 'New subscription', name: 'Name', url: 'Receiving address (HTTPS)', events: 'Events', secret: 'Secret (shown once)', test: 'Test', rotate: 'Rotate secret', deliveries: 'Delivery log', replay: 'Replay', pending: 'Pending', dead: 'Failed', delivered: 'Delivered', empty: 'No subscriptions.', copy: 'Copy the secret now; it will not be shown again.' },
    },
    mig: {
      title: 'Data migration', subtitle: 'Bring in legacy data: validate and cleanse, rehearse, import, reconcile, and roll back if needed.', kind: 'Kind of data', kinds: { employees: 'Employees', trainers: 'Trainers', programs: 'Programs', registrations: 'Registrations', attendance: 'Attendance summary', certificates: 'Certificates', pd: 'Professional development' },
      order: 'Import in this order: employees, trainers and programs first, then what refers to them.', template: 'Download the template', upload: 'Upload a file (CSV or Excel)', steps: { upload: 'Upload', map: 'Map columns', validate: 'Validate', dry: 'Rehearse', import: 'Import' },
      mapping: 'Column mapping', source: 'File column', target: 'Field', ignore: 'Ignore', defaults: 'Defaults', valueMaps: 'Value conversion', valueMapHint: 'Example: "Teacher (f)" → TEACHER', save: 'Save mapping', validateBtn: 'Validate rows', dryBtn: 'Rehearse (nothing saved)', importBtn: 'Import', rollbackBtn: 'Roll back this batch',
      total: 'Rows', valid: 'Valid', invalid: 'With errors', duplicate: 'Repeated', willCreate: 'Will be created', willUpdate: 'Will be updated', problems: 'Most common problems', errorsCsv: 'Download the errors', rows: 'Rows', recon: 'Reconciliation', matches: 'Counts and keys match', mismatch: 'Do not match — check the report', confirmImport: 'Import the valid rows now?', confirmRollback: 'Everything this batch added will be undone. Sure?', batches: 'Earlier batches', status: 'Status',
      retention: 'Uploaded data is deleted automatically after 14 days.', stepUp: 'Needs a two-step verification check.',
    },
    teams: { title: 'Microsoft Teams', create: 'Create meeting', join: 'Meeting link', cancel: 'Cancel meeting', pull: 'Pull attendance now', notReady: 'The integration is not set up.', attendance: 'Attendance by time in the call', minutes: 'Minutes', percent: 'Share', matched: 'Matched', unmatched: 'Unmatched', synced: 'Last pull', team: 'Group team', syncMembers: 'Sync members and files', open: 'Open the team' },
    ticket: { title: 'Report a problem', category: 'Kind', priority: 'Priority', subject: 'Subject', description: 'Describe the problem', screenshot: 'Screenshot (optional)', send: 'Send report', sent: 'Your report was received. Ticket number: {{no}}', queued: 'Your report is saved and will be sent shortly.', page: 'Page: {{url}}', cats: { bug: 'Bug', access: 'Access and sign-in', data: 'Wrong data', request: 'Request', other: 'Other' }, prios: { low: 'Low', normal: 'Normal', high: 'High', urgent: 'Urgent' }, mine: 'My reports' },
  },
}
