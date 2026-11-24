/** «برامجي»: the programme groups a person supervises or teaches, with their sessions, attendance and forms. */
export const dutiesAr = {
  duties: {
    nav: 'برامجي', title: 'برامجي',
    subtitle: 'كل ما أُسند إليك من برامج، سواء أُسند من «البرامج» أو من «سابر+»: مواعيدها وقاعاتها وحضورها واستباناتها.',
    none: 'لم يُسند إليك برنامج بعد.', noneHint: 'عند إسنادك مشرفاً على مجموعة أو مدرباً لها، من «البرامج» أو من «سابر+»، تظهر هنا مباشرة.',
    noneFiltered: 'لا برامج بهذا التحديد.', showAll: 'اعرض الكل',
    capacity: { supervisor: 'مشرف المجموعة', trainer: 'مدرّب', all: 'الكل', supervising: 'أشرف عليها', teaching: 'أدرّبها' },
    phase: { all: 'الكل', running: 'جارية', upcoming: 'قادمة', ended: 'منتهية' },
    summary: { today0: 'لا جلسات لك اليوم', today1: 'جلسة واحدة لك اليوم', today2: 'جلستان لك اليوم', todayFew: '{{n}} جلسات لك اليوم', todayMany: '{{n}} جلسة لك اليوم', running: 'جارية', upcoming: 'قادمة', groups: 'مجموعة' },
    when: 'الموعد', place: 'المكان', placeTbd: 'لم تُحدَّد القاعة بعد', online: 'عن بُعد', trainees: 'المتدربون المسجّلون', traineesN: '{{n}}',
    supervisor: 'المشرف', trainers: 'المدربون', you: 'أنت', noTrainers: 'لم يُسند مدرب بعد', noSupervisor: 'لم يُسند مشرف بعد', group: 'المجموعة',
    session: {
      now: 'جلسة تُعقد الآن', today: 'جلسة اليوم', next: 'الجلسة القادمة', last: 'آخر جلسة', none: 'لا جلسات مجدولة بعد', startsIn: 'تبدأ بعد', n: 'الجلسة {{n}}',
      all: 'كل الجلسات', count: '{{done}} من {{total}} انعقدت', status: { past: 'انعقدت', now: 'الآن', today: 'اليوم', later: 'قادمة' },
    },
    attendance: {
      title: 'الحضور', of: '{{present}} من {{expected}}', nobody: 'لا متدربون مسجّلون بعد', sheet: 'كشف الحضور', qr: 'رمز الحضور على الشاشة',
      qrHint: 'يُفتح في نافذة جديدة لعرضه على الشاشة: يمسحه المتدربون بهواتفهم فيُسجَّل حضورهم، ويتجدد الرمز تلقائياً.', qrLater: 'يتاح رمز الحضور يوم الجلسة.',
      noRight: 'دورك الحالي لا يملك صلاحية الحضور، فيظهر العدد دون الكشف والرمز.',
    },
    forms: {
      title: 'الاستبانات', trainee: 'استبانة المتدربين', reflection: 'تأمل المدرب الذاتي', answered: 'أجاب {{answered}} من {{total}}', show: 'اعرض الرمز', copy: 'نسخ الرابط', copied: 'نُسخ الرابط',
      mine: 'افتح استمارتي', showTitle: 'استبانة المتدربين: {{title}}', showHint: 'اعرض الرمز على الشاشة: يمسحه المتدرب بهاتفه فتُفتح استبانته لهذا البرنامج. يدخل بحسابه إن لم يكن داخلاً.', open: 'افتح الرابط',
      notOpen: 'الاستبانة غير مفتوحة الآن: من يمسح الرمز تُشرح له حالتها.',
    },
    more: { teaching: 'الحقائب والعرض في مكتب التدريس', program: 'صفحة البرنامج', here: 'الحضور ورمز الحضور والاستبانات في «برامجي»' },
    card: { title: 'برامجي', open: 'كل برامجي', line: '{{n}} مجموعة مسندة إليك' },
    updated: 'تتحدّث الأعداد تلقائياً كل دقيقة.',
  },
}

export const dutiesEn: typeof dutiesAr = {
  duties: {
    nav: 'My programmes', title: 'My programmes',
    subtitle: 'Every programme you were given, whether it was assigned in “Programmes” or in “SABER+”: its dates, rooms, attendance and surveys.',
    none: 'No programme has been assigned to you yet.', noneHint: 'As soon as you are made the supervisor of a group or one of its trainers, in “Programmes” or in “SABER+”, it appears here.',
    noneFiltered: 'No programmes match this choice.', showAll: 'Show all',
    capacity: { supervisor: 'Group supervisor', trainer: 'Trainer', all: 'All', supervising: 'I supervise', teaching: 'I teach' },
    phase: { all: 'All', running: 'Running', upcoming: 'Upcoming', ended: 'Ended' },
    summary: { today0: 'No sessions for you today', today1: 'One session for you today', today2: 'Two sessions for you today', todayFew: '{{n}} sessions for you today', todayMany: '{{n}} sessions for you today', running: 'running', upcoming: 'upcoming', groups: 'groups' },
    when: 'When', place: 'Where', placeTbd: 'The room is not set yet', online: 'Online', trainees: 'Registered trainees', traineesN: '{{n}}',
    supervisor: 'Supervisor', trainers: 'Trainers', you: 'you', noTrainers: 'No trainer assigned yet', noSupervisor: 'No supervisor assigned yet', group: 'Group',
    session: {
      now: 'A session is on now', today: 'Today’s session', next: 'Next session', last: 'Last session', none: 'No sessions scheduled yet', startsIn: 'Starts in', n: 'Session {{n}}',
      all: 'All sessions', count: '{{done}} of {{total}} held', status: { past: 'Held', now: 'Now', today: 'Today', later: 'Upcoming' },
    },
    attendance: {
      title: 'Attendance', of: '{{present}} of {{expected}}', nobody: 'No trainees registered yet', sheet: 'Attendance sheet', qr: 'Attendance code on screen',
      qrHint: 'Opens in a new window to show on the screen: trainees scan it with their phones and their attendance is recorded; the code renews by itself.', qrLater: 'The attendance code is available on the day of the session.',
      noRight: 'Your current role does not hold the attendance right, so the count is shown without the sheet and the code.',
    },
    forms: {
      title: 'Surveys', trainee: 'Trainee survey', reflection: 'Trainer self-reflection', answered: '{{answered}} of {{total}} answered', show: 'Show the code', copy: 'Copy the link', copied: 'Link copied',
      mine: 'Open my form', showTitle: 'Trainee survey: {{title}}', showHint: 'Show the code on the screen: a trainee scans it with their phone and their survey for this programme opens. They sign in first if they are not signed in.', open: 'Open the link',
      notOpen: 'The survey is not open now: whoever scans the code is told where it stands.',
    },
    more: { teaching: 'Kits and presenting in the teaching desk', program: 'Programme page', here: 'Attendance, the attendance code and surveys are in “My programmes”' },
    card: { title: 'My programmes', open: 'All my programmes', line: '{{n}} groups assigned to you' },
    updated: 'The counts refresh by themselves every minute.',
  },
}
