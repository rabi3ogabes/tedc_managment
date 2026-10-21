/** Settings → Data: importing the Ministry's trainee extract from the browser, and removing the demo data. */
export const datasetAr = {
  dataset: {
    intro: 'هنا تُحمَّل بيانات الوزارة الحقيقية (العاملون والدورات التي حضروها) إلى المنصة، ثم تُزال البيانات التجريبية. كل خطوة آمنة للتكرار، ولا تُعرض أي بيانات شخصية في التقارير — أعداد ورموز فقط.',
    import: {
      title: 'استيراد بيانات الوزارة', hint: 'ملفات «بيانات الدورات التدريبية للعاملين بالمدارس الحكومية» (‎.xlsx) كما تصدرها الوزارة — ملف واحد أو أكثر.',
      drop: 'اسحب الملفات إلى هنا', dropHint: 'حتى 10 ملفات ‎.xlsx، حجم كل ملف حتى 50 ميغابايت', choose: 'اختيار الملفات',
      start: 'رفع الملفات وبدء الاستيراد ({{n}})', startEmpty: 'اختر الملفات أولاً', safe: 'آمن للتكرار: الموجود من قبل لا يتكرر.', uploading: 'جارٍ رفع الملفات إلى التخزين الآمن…',
      progress: 'الخطوة {{done}} من {{total}}', keepOpen: 'أبقِ هذه الصفحة مفتوحة حتى ينتهي الاستيراد (بضع دقائق).', resume: 'متابعة الاستيراد',
      another: 'استيراد ملفات أخرى', lastRun: 'آخر استيراد: {{date}} — {{people}} موظف و{{courses}} دورة.',
    },
    steps: { setup: 'قراءة الملفات، المدارس والوظائف', people: 'الموظفون — الجزء {{k}} من {{n}}', courses: 'الدورات — {{name}}' },
    n: { rows: 'صف في الملفات', people: 'شخص', peopleNew: 'موظف جديد', courses: 'دورة مستوردة', schoolsNew: 'جهة جديدة' },
    report: {
      ok: 'اكتمل الاستيراد وتطابقت الأرقام مع الملفات', notOk: 'اكتمل الاستيراد لكن الأرقام لا تتطابق مع الملفات — راجع الملاحظات', time: 'المدة: {{s}} ثانية',
      rows: 'الصفوف في الملفات', rowsSub: 'مرفوض {{a}}', people: 'الأشخاص', peopleSub: 'جديد {{a}} · محدَّث {{b}}', numbers: 'الأرقام الوظيفية',
      schools: 'المدارس والجهات', schoolsSub: 'مطابقة {{a}} · جديدة {{b}}', courses: 'الدورات', coursesSub: 'جديدة {{a}} · موجودة من قبل {{b}} · مرفوضة {{c}}', hours: 'ساعات التدريب في الملفات', hoursSub: 'أُضيف منها الآن {{a}}',
      issues: 'ملاحظات للمراجعة',
    },
    issue: {
      identity_missing: 'صف بلا رقم وظيفي أو هوية', empno_filed_under_two_ids: 'رقم وظيفي لهويتين', empno_belongs_to_another_person: 'رقم وظيفي لشخص آخر',
      course_name_missing: 'دورة بلا اسم', start_date_invalid: 'تاريخ بداية غير صالح', end_before_start: 'النهاية قبل البداية', hours_invalid: 'ساعات غير صالحة',
    },
    purge: {
      title: 'إزالة البيانات التجريبية', hint: 'تُحذف البرامج والتسجيلات والشهادات والاستبيانات والحقائب والإشعارات وكل الحسابات التجريبية مرة واحدة، بعد حفظ نسخة احتياطية منها.',
      goes: 'سيُحذف', goesSub: 'صفاً في {{tables}} جدولاً، بعد حفظها في نسخة احتياطية', details: 'التفاصيل حسب الجدول',
      stays: 'سيبقى', staysPeople: '{{n}} موظفاً مستورداً و{{c}} دورة', staysSchools: '{{n}} مدرسة وجهة (القائمة الرسمية والمستوردة)', staysSetup: 'كل الإعدادات: الأدوار والصلاحيات والهوية والقوائم المرجعية ومركز المساعدة والقوالب',
      adminTitle: 'مدير النظام الذي يبقى', adminHint: 'تُحذف كل الحسابات التجريبية — ومنها حسابك الحالي إن كان تجريبياً. أدخل بيانات المدير الذي سيدير المنصة؛ كلمة المرور التي تكتبها هنا هي التي يدخل بها مباشرة (تخضع لسياسة كلمات المرور).',
      name: 'الاسم', email: 'البريد الإلكتروني', password: 'كلمة المرور', password2: 'تأكيد كلمة المرور', confirm: 'للتأكيد اكتب: {{phrase}}',
      button: 'حذف البيانات التجريبية نهائياً', done: 'تم: حُذف {{rows}} صفاً في {{tables}} جدولاً.', backup: 'النسخة الاحتياطية ({{rows}} صف) محفوظة في التخزين الخاص:',
      signIn: 'حُذف حسابك التجريبي. ادخل الآن بحساب المدير {{email}} وكلمة المرور التي كتبتها.', goSignIn: 'تسجيل الدخول',
      computing: 'جارٍ حساب ما سيُحذف وما سيبقى…', previewFailed: 'تعذّر حساب المعاينة.', retry: 'إعادة المحاولة',
      already: 'أُزيلت البيانات التجريبية في {{date}} — المدير: {{admin}}', importFirst: 'استورد بيانات الوزارة أولاً؛ بعدها يمكن إزالة البيانات التجريبية.',
    },
  },
}
export const datasetEn = {
  dataset: {
    intro: "Here the Ministry's real data (the staff and the courses they attended) is loaded into the platform, and then the demo data is removed. Every step is safe to repeat, and no personal data appears in the reports — counts and codes only.",
    import: {
      title: 'Import the Ministry data', hint: 'The «training courses of government school staff» files (.xlsx) as the Ministry exports them — one or more files.',
      drop: 'Drop the files here', dropHint: 'Up to 10 .xlsx files, up to 50 MB each', choose: 'Choose files',
      start: 'Upload and start the import ({{n}})', startEmpty: 'Choose the files first', safe: 'Safe to repeat: what is already there is not duplicated.', uploading: 'Uploading the files to secure storage…',
      progress: 'Step {{done}} of {{total}}', keepOpen: 'Keep this page open until the import ends (a few minutes).', resume: 'Continue the import',
      another: 'Import other files', lastRun: 'Last import: {{date}} — {{people}} staff and {{courses}} courses.',
    },
    steps: { setup: 'Reading the files, schools and job titles', people: 'Staff — part {{k}} of {{n}}', courses: 'Courses — {{name}}' },
    n: { rows: 'rows in the files', people: 'people', peopleNew: 'new staff', courses: 'courses imported', schoolsNew: 'new organisations' },
    report: {
      ok: 'The import is complete and the numbers match the files', notOk: 'The import is complete but the numbers do not match the files — see the notes', time: 'Time: {{s}} s',
      rows: 'Rows in the files', rowsSub: 'Rejected {{a}}', people: 'People', peopleSub: 'New {{a}} · updated {{b}}', numbers: 'Employee numbers',
      schools: 'Schools and organisations', schoolsSub: 'Matched {{a}} · new {{b}}', courses: 'Courses', coursesSub: 'New {{a}} · already there {{b}} · rejected {{c}}', hours: 'Training hours in the files', hoursSub: 'Added now {{a}}',
      issues: 'Notes to review',
    },
    issue: {
      identity_missing: 'Row without employee number or ID', empno_filed_under_two_ids: 'Employee number under two IDs', empno_belongs_to_another_person: 'Employee number of another person',
      course_name_missing: 'Course without a name', start_date_invalid: 'Invalid start date', end_before_start: 'Ends before it starts', hours_invalid: 'Invalid hours',
    },
    purge: {
      title: 'Remove the demo data', hint: 'Programmes, registrations, certificates, surveys, kits, notifications and every demo account are deleted once, after a backup of them is saved.',
      goes: 'Will be deleted', goesSub: 'rows in {{tables}} tables, after they are saved in a backup', details: 'Details by table',
      stays: 'Will stay', staysPeople: '{{n}} imported staff and {{c}} courses', staysSchools: '{{n}} schools and organisations (official list and imported)', staysSetup: 'All the setup: roles and permissions, identity, reference lists, help centre and templates',
      adminTitle: 'The administrator who stays', adminHint: 'Every demo account is deleted — including yours if it is a demo one. Enter the administrator who will run the platform; the password typed here is the one they sign in with straight away (the password policy applies).',
      name: 'Name', email: 'E-mail', password: 'Password', password2: 'Confirm the password', confirm: 'To confirm, type: {{phrase}}',
      button: 'Delete the demo data for good', done: 'Done: {{rows}} rows deleted in {{tables}} tables.', backup: 'The backup ({{rows}} rows) is kept in private storage:',
      signIn: 'Your demo account was deleted. Sign in now as {{email}} with the password you typed.', goSignIn: 'Sign in',
      computing: 'Working out what goes and what stays…', previewFailed: 'The preview could not be worked out.', retry: 'Try again',
      already: 'The demo data was removed on {{date}} — administrator: {{admin}}', importFirst: 'Import the Ministry data first; then the demo data can be removed.',
    },
  },
}
