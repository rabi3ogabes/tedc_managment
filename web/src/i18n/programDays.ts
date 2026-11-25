/** The programme list by day: the row of days above the list, a date typed in the search box, and the day's agenda in the list. */
export const progDayAr = {
  progDay: {
    title: 'البرامج حسب يوم التدريب', all: 'كل التواريخ', allHint: 'اختر يوماً لترى البرامج التي تتدرب فيه',
    today: 'اليوم', tomorrow: 'غداً', yesterday: 'أمس', prev: 'اليوم السابق', next: 'اليوم التالي', earlier: 'أيام أسبق', later: 'أيام لاحقة',
    pick: 'اختر يوماً من التقويم', backToday: 'اليوم', clear: 'كل التواريخ', dayOff: 'عطلة',
    count: { none: 'لا تدريب', one: 'برنامج واحد', two: 'برنامجان', few: '{{n}} برامج', many: '{{n}} برنامجاً' },
    chip: '{{day}}: {{count}}', legend: { lit: 'مضيء: فيه تدريب', off: 'مطفأ: لا تدريب', today: 'هذا اليوم' },
    heading: 'برامج يوم {{day}}', column: 'موعد اليوم', more: 'و{{n}} أخرى',
    search: { placeholder: 'ابحث باسم البرنامج أو رمزه، أو اكتب تاريخاً مثل 16/11/2026', apply: 'اعرض برامج يوم {{day}}' },
    empty: {
      title: 'لا تدريب في هذا اليوم', text: 'لا برنامج له جلسة يوم {{day}}.', filtered: 'لا برنامج بهذه التصفية له جلسة يوم {{day}}. أزل التصفية أو اختر يوماً آخر.',
      offDay: 'يوم {{day}} عطلة، ولا تدريب فيه.', nearest: 'أقرب يوم فيه تدريب', before: 'قبله', after: 'بعده',
    },
  },
}

export const progDayEn = {
  progDay: {
    title: 'Programmes by training day', all: 'All dates', allHint: 'Pick a day to see the programmes that train on it',
    today: 'Today', tomorrow: 'Tomorrow', yesterday: 'Yesterday', prev: 'Previous day', next: 'Next day', earlier: 'Earlier days', later: 'Later days',
    pick: 'Pick a day from the calendar', backToday: 'Today', clear: 'All dates', dayOff: 'Day off',
    count: { none: 'No training', one: '1 programme', two: '2 programmes', few: '{{n}} programmes', many: '{{n}} programmes' },
    chip: '{{day}}: {{count}}', legend: { lit: 'Lit: training that day', off: 'Off: no training', today: 'this day' },
    heading: 'Programmes on {{day}}', column: 'That day', more: 'and {{n}} more',
    search: { placeholder: 'Search by programme name or code, or type a date such as 16/11/2026', apply: 'Show the programmes of {{day}}' },
    empty: {
      title: 'No training on this day', text: 'No programme has a session on {{day}}.', filtered: 'No programme with these filters has a session on {{day}}. Clear the filters or pick another day.',
      offDay: '{{day}} is a day off, with no training.', nearest: 'Nearest day with training', before: 'Before', after: 'After',
    },
  },
}
