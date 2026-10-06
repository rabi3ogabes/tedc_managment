/** Reports hub and builder, role dashboards, live KPI dashboard (Phase 12). */
export const reportsAr = {
  rep: {
    navReports: 'التقارير', navKpi: 'مؤشرات الأداء الحية', title: 'التقارير', subtitle: 'تقارير جاهزة لدورك، ويمكنك بناء تقاريرك الخاصة وجدولتها.',
    cat: { all: 'الكل', favorites: 'المفضلة', admin: 'الإدارة', supervisor: 'المشرفون', trainer: 'المدربون', manager: 'المديرون المباشرون', trainee: 'المتدربون', qa: 'الجودة', kit: 'الحقائب', general: 'عامة' },
    search: 'ابحث في التقارير', newReport: 'تقرير جديد', empty: 'لا توجد تقارير مطابقة.', system: 'جاهز', mine: 'خاص بي', favorite: 'تفضيل', copy: 'نسخة قابلة للتعديل', edit: 'تعديل', delete: 'حذف', deleteAsk: 'حذف هذا التقرير؟',
    run: 'تشغيل', preview: 'عرض', filters: 'المرشحات', from: 'من تاريخ', to: 'إلى تاريخ', apply: 'تطبيق', rows: '{{n}} صفًا', page: 'صفحة {{p}} من {{n}}', noRows: 'لا توجد بيانات تطابق الاختيار.', totals: 'الإجمالي',
    export: 'تصدير', xlsx: 'Excel', pdf: 'PDF', docx: 'Word', exporting: 'جارٍ إعداد الملف…', queued: 'التقرير كبير؛ سيصلك إشعار عند جاهزيته.', runs: 'سجل التشغيل', status: { queued: 'في الانتظار', running: 'قيد الإعداد', ready: 'جاهز', failed: 'فشل' }, download: 'تنزيل', expires: 'ينتهي في', print: 'طباعة',
    kind: { table: 'جدول', matrix: 'مصفوفة', composite: 'عدة جداول', sheet: 'كشف للطباعة' },
    schedule: { title: 'جدولة التقرير', button: 'جدولة', frequency: 'التكرار', daily: 'يوميًا', weekly: 'أسبوعيًا', monthly: 'شهريًا', formats: 'الصيغ', recipients: 'المستلمون', users: 'مستخدمون', roles: 'أدوار', emails: 'بريد إلكتروني (افصل بفاصلة)', saved: 'تمت الجدولة.', list: 'التقارير المجدولة', next: 'الإرسال التالي', last: 'آخر إرسال', error: 'آخر خطأ', remove: 'إلغاء الجدولة', empty: 'لا توجد جداول.' },
    builder: {
      title: 'منشئ التقارير', dataset: 'مصدر البيانات', columns: 'الأعمدة', addColumn: 'إضافة عمود', aggregate: 'تجميع', none: 'بدون', filters: 'المرشحات', addFilter: 'إضافة مرشح', groupBy: 'التجميع حسب', sort: 'الترتيب', chart: 'الرسم البياني', chartNone: 'بدون رسم', chartBar: 'أعمدة', chartLine: 'خط', chartDonut: 'دائري', x: 'المحور الأفقي', y: 'القيمة',
      titleAr: 'العنوان بالعربية', titleEn: 'العنوان بالإنجليزية', visibility: 'المشاركة', private: 'خاص بي', role: 'أدوار محددة', everyone: 'الجميع', adjustable: 'يعدّله المستخدم عند التشغيل', save: 'حفظ التقرير', saved: 'تم حفظ التقرير.', previewTitle: 'معاينة (٢٥ صفًا)', personal: 'بيانات شخصية', pickDataset: 'اختر مصدر البيانات أولًا.', value: 'القيمة', dir: 'الاتجاه', asc: 'تصاعدي', desc: 'تنازلي', needColumn: 'اختر عمودًا واحدًا على الأقل وأدخل العنوان.',
      op: { eq: 'يساوي', ne: 'لا يساوي', contains: 'يحتوي', starts: 'يبدأ بـ', in: 'ضمن', empty: 'فارغ', not_empty: 'غير فارغ', gt: 'أكبر من', gte: 'أكبر أو يساوي', lt: 'أصغر من', lte: 'أصغر أو يساوي', between: 'بين', is_true: 'نعم', is_false: 'لا' },
      agg: { count: 'عدد', count_distinct: 'عدد غير مكرر', sum: 'مجموع', avg: 'متوسط', min: 'أدنى', max: 'أعلى' },
    },
    dash: {
      title: 'لوحتي', range: 'الفترة', customise: 'تخصيص', done: 'تم', hide: 'إخفاء', show: 'إظهار', up: 'أعلى', down: 'أسفل', drill: 'التفاصيل', noData: 'لا بيانات.', hiddenCount: '{{n}} عناصر مخفية', target: 'المستهدف', saved: 'تم حفظ ترتيب لوحتك.',
      label: { passed: 'ناجح', failed: 'راسب', pending: 'قيد الانتظار', ongoing: 'جارية', open: 'متاحة', planned: 'مخطط', completed: 'مكتملة', draft: 'مسودة', approved: 'معتمدة', published: 'منشورة', in_review: 'قيد المراجعة', submitted: 'مُقدّمة', done: 'منتهية', requested: 'مطلوب', assigned: 'مُسند', delivered: 'مُسلّم', booked: 'محجوز', cancelled: 'ملغى' },
      kind: { highest: 'الأعلى', lowest: 'الأدنى' }, presets: 'قوالب لوحات الأدوار', presetHint: 'اختر الكتل التي تظهر لكل دور؛ يخصّص كل مستخدم ترتيبها ضمن هذا القالب.', presetSaved: 'تم حفظ القالب.',
    },
    kpi: {
      title: 'لوحة مؤشرات الأداء الحية', subtitle: 'القيم الحالية مقابل المستهدفات للأيام الثلاثين الأخيرة. تتجدد كل ٣٠ ثانية.', target: 'المستهدف', status: { ok: 'محقق', breach: 'خارج المستهدف' }, refresh: 'قياس الآن', peak: 'الذروة (٢٤ ساعة)', editTargets: 'تعديل المستهدفات', saveTargets: 'حفظ المستهدفات', report: 'تقرير شهري', month: 'الشهر', breaches: '{{n}} مؤشرات خارج المستهدف', allOk: 'كل المؤشرات ضمن المستهدف',
      integrity: 'تفاصيل سلامة البيانات', violations: 'مخالفات', records: 'سجلات مفحوصة', blocked: 'محاولات محجوبة (٣٠ يومًا)', samples: 'عينات', updated: 'آخر قياس',
      check: { registrations_without_program_or_employee: 'تسجيلات بلا برنامج أو موظف', attendance_checkout_before_checkin: 'خروج قبل الدخول', duplicate_employee_numbers: 'أرقام وظيفية مكررة', certificates_for_unfinished_registrations: 'شهادات لتسجيلات ملغاة', completed_without_approval: 'مكتملة دون اعتماد', impossible_percentages: 'نسب مستحيلة', group_dates_reversed: 'تواريخ مجموعة معكوسة' },
    },
  },
}

export const reportsEn = {
  rep: {
    navReports: 'Reports', navKpi: 'Live KPIs', title: 'Reports', subtitle: 'Ready-made reports for your role; build and schedule your own.',
    cat: { all: 'All', favorites: 'Favourites', admin: 'Administration', supervisor: 'Supervisors', trainer: 'Trainers', manager: 'Direct managers', trainee: 'Trainees', qa: 'Quality', kit: 'Kits', general: 'General' },
    search: 'Search reports', newReport: 'New report', empty: 'No matching reports.', system: 'Built-in', mine: 'Mine', favorite: 'Favourite', copy: 'Editable copy', edit: 'Edit', delete: 'Delete', deleteAsk: 'Delete this report?',
    run: 'Run', preview: 'View', filters: 'Filters', from: 'From', to: 'To', apply: 'Apply', rows: '{{n}} rows', page: 'Page {{p}} of {{n}}', noRows: 'No data matches.', totals: 'Total',
    export: 'Export', xlsx: 'Excel', pdf: 'PDF', docx: 'Word', exporting: 'Preparing the file…', queued: 'This report is large; you will be notified when it is ready.', runs: 'Run history', status: { queued: 'Queued', running: 'Running', ready: 'Ready', failed: 'Failed' }, download: 'Download', expires: 'Expires', print: 'Print',
    kind: { table: 'Table', matrix: 'Matrix', composite: 'Several tables', sheet: 'Printable sheet' },
    schedule: { title: 'Schedule this report', button: 'Schedule', frequency: 'Repeat', daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly', formats: 'Formats', recipients: 'Recipients', users: 'People', roles: 'Roles', emails: 'E-mail addresses (comma separated)', saved: 'Scheduled.', list: 'Scheduled reports', next: 'Next send', last: 'Last send', error: 'Last error', remove: 'Unschedule', empty: 'Nothing scheduled.' },
    builder: {
      title: 'Report builder', dataset: 'Data source', columns: 'Columns', addColumn: 'Add a column', aggregate: 'Calculate', none: 'None', filters: 'Filters', addFilter: 'Add a filter', groupBy: 'Group by', sort: 'Sort', chart: 'Chart', chartNone: 'No chart', chartBar: 'Bars', chartLine: 'Line', chartDonut: 'Donut', x: 'Horizontal axis', y: 'Value',
      titleAr: 'Title (Arabic)', titleEn: 'Title (English)', visibility: 'Sharing', private: 'Only me', role: 'Chosen roles', everyone: 'Everyone', adjustable: 'Changeable when run', save: 'Save report', saved: 'Report saved.', previewTitle: 'Preview (25 rows)', personal: 'Personal data', pickDataset: 'Choose a data source first.', value: 'Value', dir: 'Direction', asc: 'Ascending', desc: 'Descending', needColumn: 'Pick at least one column and enter a title.',
      op: { eq: 'equals', ne: 'is not', contains: 'contains', starts: 'starts with', in: 'is one of', empty: 'is empty', not_empty: 'is not empty', gt: 'greater than', gte: 'at least', lt: 'less than', lte: 'at most', between: 'between', is_true: 'yes', is_false: 'no' },
      agg: { count: 'Count', count_distinct: 'Distinct count', sum: 'Sum', avg: 'Average', min: 'Lowest', max: 'Highest' },
    },
    dash: {
      title: 'My dashboard', range: 'Period', customise: 'Customise', done: 'Done', hide: 'Hide', show: 'Show', up: 'Up', down: 'Down', drill: 'Details', noData: 'No data.', hiddenCount: '{{n}} hidden', target: 'Target', saved: 'Your dashboard layout is saved.',
      label: { passed: 'Passed', failed: 'Failed', pending: 'Pending', ongoing: 'Ongoing', open: 'Open', planned: 'Planned', completed: 'Completed', draft: 'Draft', approved: 'Approved', published: 'Published', in_review: 'In review', submitted: 'Submitted', done: 'Done', requested: 'Requested', assigned: 'Assigned', delivered: 'Delivered', booked: 'Booked', cancelled: 'Cancelled' },
      kind: { highest: 'Highest', lowest: 'Lowest' }, presets: 'Role dashboard presets', presetHint: 'Choose the widgets each role sees; each person arranges them within it.', presetSaved: 'Preset saved.',
    },
    kpi: {
      title: 'Live KPI dashboard', subtitle: 'Current values against targets, with the last 30 days. Refreshes every 30 seconds.', target: 'Target', status: { ok: 'Met', breach: 'Off target' }, refresh: 'Measure now', peak: 'Peak (24 h)', editTargets: 'Edit targets', saveTargets: 'Save targets', report: 'Monthly report', month: 'Month', breaches: '{{n}} indicators off target', allOk: 'All indicators are on target',
      integrity: 'Data integrity details', violations: 'Violations', records: 'Records checked', blocked: 'Blocked attempts (30 days)', samples: 'Samples', updated: 'Last measured',
      check: { registrations_without_program_or_employee: 'Registrations without a program or employee', attendance_checkout_before_checkin: 'Check-out before check-in', duplicate_employee_numbers: 'Duplicate employee numbers', certificates_for_unfinished_registrations: 'Certificates for cancelled registrations', completed_without_approval: 'Completed without approval', impossible_percentages: 'Impossible percentages', group_dates_reversed: 'Group dates reversed' },
    },
  },
}
