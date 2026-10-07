/** The animated tour of the training centre on the home page. */
export const cmpAr = { campus: {
  eyebrow: 'مركز التدريب والتطوير', title: 'رحلة تدريبية داخل المركز', text: 'تتبّع متدربًا من لحظة وصوله إلى مجمّع وزارة التربية والتعليم والتعليم العالي حتى قياس أثر التدريب، على مخططات المبنى الفعلية.',
  live: 'مباشر', floorTitle: 'الطابق', floors: { ground: 'الطابق الأرضي', first: 'الطابق الأول', day: 'المجمّع', night: 'المجمّع ليلًا' },
  step: 'الخطوة {{n}} من {{total}}', play: 'تشغيل', pause: 'إيقاف مؤقت', next: 'التالي', prev: 'السابق', replay: 'أعد الرحلة', open: 'اطّلع على المزيد',
  done: 'أتممت الرحلة', doneText: 'من الحاجة إلى الأثر: كل خطوة موثّقة في المنصة.', stepsLabel: 'مراحل الرحلة', illustration: 'مسار توضيحي: توزيع المراحل على القاعات للتعريف بالرحلة، وليس تخصيصًا فعليًا للقاعات.',
  planAlt: { ground: 'مخطط الطابق الأرضي لمركز التدريب', first: 'مخطط الطابق الأول لمركز التدريب', day: 'منظر جوي لمجمّع الوزارة نهارًا', night: 'منظر جوي لمجمّع الوزارة ليلًا' },
  stats: { schools: 'مدرسة تخدمها المنصة', participants: 'متدرب', programs: 'برنامج تدريبي', training_hours: 'ساعة تدريبية', certificates: 'شهادة صادرة', satisfaction: 'رضا المتدربين', trainers: 'مدرب', employees: 'موظف مسجّل' },
  stages: {
    arrive: { title: 'الوصول إلى المجمّع', place: 'مجمّع الوزارة', text: 'تبدأ كل رحلة من مجمّع المركز، الذي يخدم مدارس الدولة ومعلميها وإدارييها.' },
    needs: { title: 'تحديد الاحتياج والتخطيط', place: 'مبنى الإدارة', text: 'تُجمع احتياجات المدارس والأقسام من الاستبيانات والأداء، وتُبنى منها الخطة التدريبية السنوية.' },
    register: { title: 'التسجيل والاعتماد', place: 'البهو المركزي', text: 'يُرشَّح المتدرب، فيعتمده مديره ثم المركز، ويُحجز له مقعد بحسب الأولوية والسعة.' },
    train: { title: 'التدريب في القاعات', place: 'قاعات التدريب 1 و2', text: 'جلسات مع المدرب، ويُسجَّل الحضور برمز QR أو بصمة الموقع، مع متابعة الأعذار والإجازات.' },
    practise: { title: 'التطبيق في المعامل', place: 'معمل الحاسب', text: 'تمارين عملية ومهام تُسلَّم على المنصة ويراجعها المدرب بنموذج تقييم.' },
    assess: { title: 'التقويم والاختبار', place: 'واحة المعرفة', text: 'اختبارات وأنشطة تقيس ما اكتُسب، من بنوك أسئلة متنوعة، وتُصحَّح آليًا أو يدويًا.' },
    certify: { title: 'الشهادة والتوثيق', place: 'مبنى الإدارة', text: 'عند استيفاء شروط النجاح تصدر شهادة عليها رمز تحقق، وتُضاف الساعات إلى جواز التدريب.' },
    impact: { title: 'قياس الأثر', place: 'المجمّع', text: 'بعد أسابيع يُقاس أثر التدريب على الممارسة في المدرسة، وتعود النتائج لتحسين الخطة القادمة.' },
  },
} }
export const cmpEn = { campus: {
  eyebrow: 'Training & Development Center', title: 'A training journey through the centre', text: 'Follow a trainee from arriving at the Ministry of Education and Higher Education campus to measuring the impact of the training, on the real building plans.',
  live: 'Live', floorTitle: 'Floor', floors: { ground: 'Ground floor', first: 'First floor', day: 'The campus', night: 'The campus at night' },
  step: 'Step {{n}} of {{total}}', play: 'Play', pause: 'Pause', next: 'Next', prev: 'Back', replay: 'Replay the journey', open: 'Learn more',
  done: 'Journey complete', doneText: 'From need to impact: every step is recorded in the platform.', stepsLabel: 'Stages of the journey', illustration: 'An illustrative route: the stages are placed in rooms to explain the journey, not as the actual use of each room.',
  planAlt: { ground: 'Plan of the training centre\'s ground floor', first: 'Plan of the training centre\'s first floor', day: 'Aerial view of the ministry campus by day', night: 'Aerial view of the ministry campus at night' },
  stats: { schools: 'schools served by the platform', participants: 'trainees', programs: 'training programs', training_hours: 'training hours', certificates: 'certificates issued', satisfaction: 'trainee satisfaction', trainers: 'trainers', employees: 'registered staff' },
  stages: {
    arrive: { title: 'Arrive at the campus', place: 'The ministry campus', text: 'Every journey starts at the centre\'s campus, which serves the State\'s schools, teachers and administrators.' },
    needs: { title: 'Needs and planning', place: 'Administration building', text: 'Needs from schools and departments are collected from surveys and performance data, and the annual training plan is built from them.' },
    register: { title: 'Registration and approval', place: 'Central atrium', text: 'The trainee is nominated, approved by the manager and then the centre, and a seat is held by priority and capacity.' },
    train: { title: 'Training in the rooms', place: 'Training rooms 1–2', text: 'Sessions with the trainer; attendance is recorded by QR code or location, with excuses and leaves followed up.' },
    practise: { title: 'Hands-on in the labs', place: 'Computer lab', text: 'Practical exercises and tasks submitted on the platform and marked by the trainer with a rubric.' },
    assess: { title: 'Assessment', place: 'Knowledge Oasis', text: 'Tests and activities measure what was learned, from varied question banks, marked automatically or by hand.' },
    certify: { title: 'Certificate and record', place: 'Administration building', text: 'When the pass conditions are met a certificate with a verification code is issued and the hours join the training passport.' },
    impact: { title: 'Measuring impact', place: 'The campus', text: 'Weeks later the effect of the training on practice in school is measured, and the results improve the next plan.' },
  },
} }
