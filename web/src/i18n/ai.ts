/** AI: assistant, recommendations, smart feedback, adaptive path, forecasts and risks, AI settings (Phase 15). */
export const aiAr = {
  aix: {
    nav: { forecasts: 'التوقعات والمخاطر', adaptive: 'قواعد التعلّم التكيّفي' },
    assistant: {
      title: 'مساعد التدريب', subtitle: 'يجيب من محتوى المنصة وبياناتك فقط.', open: 'اسأل المساعد', close: 'إغلاق', placeholder: 'اكتب سؤالك عن برامجك أو دروسك أو جدولك…', send: 'إرسال', thinking: 'جارٍ البحث في المحتوى…',
      empty: 'اسألني عن دروسك وبرامجك وجدولك وشهاداتك وساعاتك.', suggestions: ['متى جلستي القادمة؟', 'كم ساعة تطوير مهني لدي؟', 'ما شهاداتي؟', 'ما تقدمي في الدورات؟'], sources: 'المصادر', helpful: 'مفيد', notHelpful: 'غير مفيد', thanks: 'شكرًا لملاحظتك.',
      notSure: 'لست متأكدًا من هذه الإجابة. يمكنك:', askTrainer: 'اسأل المدرب', askSupport: 'تواصل مع الدعم', pickProgram: 'اختر البرنامج', sentTrainer: 'أُرسل سؤالك إلى المدرب.', sentSupport: 'أُنشئ طلب دعم.', sourceAi: 'مولَّد بنموذج ذكاء اصطناعي', sourceRules: 'مقتبس من المحتوى', refused: 'خارج نطاق التدريب', newChat: 'محادثة جديدة', history: 'السجل', error: 'تعذّر الحصول على إجابة.',
    },
    rec: { why: 'لماذا يُقترح لك؟', helpful: 'مفيد', dismiss: 'لا يناسبني', dismissed: 'لن نعرضه عليك قريبًا.', score: 'الملاءمة' },
    feedback: { title: 'ملاحظات ذكية على إجاباتك', mistake: 'اخترتَ', right: 'الإجابة الصحيحة', sameMistake: 'أخطأ {{n}}٪ من الزملاء بنفس الاختيار', skills: 'الكفايات', review: 'ما تراجعه', open: 'افتح الدرس', none: 'لا ملاحظات متاحة لهذا الاختبار.' },
    draft: {
      title: 'مسودة ملاحظات بالذكاء الاصطناعي', hint: 'مسودة تساعدك فقط — لا تُحتسب درجة إلا بعد اعتمادك.', suggested: 'الدرجة المقترحة', accept: 'اعتماد', edit: 'تعديل واعتماد', reject: 'رفض المسودة', score: 'الدرجة', comment: 'التعليق', criteria: 'المعايير', source: { ai: 'نموذج ذكاء اصطناعي', rules: 'تغطية الكلمات المفتاحية (بدون نموذج)' },
      status: { draft: 'مسودة', accepted: 'مُعتمدة', edited: 'مُعدّلة', rejected: 'مرفوضة' }, saved: 'تم اعتماد الدرجة.', rejected: 'رُفضت المسودة.', met: 'تحقق',
    },
    path: {
      title: 'مسارك الشخصي', hint: 'يتكيّف المسار مع نتائجك في الاختبارات.', skipped: 'تجاوزتَ هذه الوحدة لإتقانك لها', mastery: 'الإتقان', remedial: 'موصى به لك', weak: 'مستوى الإتقان {{m}}٪ — راجع هذا الدرس', mastered: 'أتقنتَ {{skill}} ({{m}}٪)', none: 'لا توجد تعديلات على مسارك بعد.',
    },
    adaptive: {
      title: 'قواعد التعلّم التكيّفي', hint: 'تجاوز وحدة عند إتقان كفاية، وأضف درسًا علاجيًا عند ضعفها. تُحتسب الإتقان من نتائج الاختبارات المرتبطة بالكفايات.', add: 'إضافة قاعدة', skill: 'الكفاية', action: 'الإجراء', skip: 'تجاوز وحدة (اختبار إعفاء)', addLesson: 'إضافة درس علاجي', module: 'الوحدة', lesson: 'الدرس', skipAt: 'الإتقان للتجاوز', below: 'أقل من', save: 'حفظ القواعد', saved: 'حُفظت القواعد.',
      generate: 'توليد مسودة درس علاجي', generated: 'أُنشئت مسودة الدرس — راجعها وانشرها من محرر المحتوى.', draftNote: 'المحتوى المولَّد يبقى مسودة حتى ينشره المدرب.', rules: 'القواعد', none: 'لا قواعد بعد.',
    },
    fc: {
      title: 'التوقعات والمخاطر', subtitle: 'احتياجات التطوير المهني المتوقعة وقوائم المخاطر المبكرة.', tabs: { forecasts: 'التوقعات', risks: 'المخاطر' }, dimension: { competency: 'الكفايات', job: 'المسميات الوظيفية', school: 'المدارس' }, year: 'السنة', run: 'تحديث التوقعات', ran: 'تم تحديث التوقعات وقوائم المخاطر.',
      label: 'البند', value: 'المتوقع', range: 'المدى', confidence: 'الثقة', why: 'التفسير', history: 'التاريخ', toPlan: 'إضافة إلى الخطة', pickPlan: 'اختر خطة مسودة', added: 'أُضيفت {{n}} بنود مقترحة إلى الخطة.', none: 'لا توقعات بعد — شغّل التحديث.',
      risk: { hours_shortfall: 'نقص ساعات التطوير', licence_gap: 'ترخيص يقترب من الانتهاء', group_underfill: 'مجموعة غير مكتملة', low_satisfaction: 'رضا منخفض' }, score: 'الخطورة', resolve: 'تمت المعالجة', noRisks: 'لا مخاطر مفتوحة.', reasons: 'الأسباب',
    },
    set: {
      title: 'الذكاء الاصطناعي والخصوصية', subtitle: 'أين تُعالَج بياناتك، وما الذي يُخفى، وأي نموذج لكل ميزة.', residency: 'الالتزام بموقع البيانات', residencyHint: 'عند التفعيل لا تُرسل بيانات شخصية إلى نموذج خارج قطر إلا إذا سمحتَ بذلك لكل ميزة (قانون 13/2016).',
      redaction: 'إخفاء المعرّفات الشخصية قبل الإرسال', retention: 'مدة حفظ السجلات (أيام)', features: { recommendations: 'التوصيات', feedback: 'ملاحظات الاختبارات', adaptive: 'التعلّم التكيّفي', forecasts: 'التنبؤ', assistant: 'مساعد المتدرب' },
      enabled: 'مفعّلة', model: 'النموذج', defaultModel: 'النموذج الافتراضي للنص', allowExternal: 'السماح بنموذج خارجي لبيانات شخصية', usable: 'جاهزة', notUsable: 'ستعمل بالقواعد فقط', residencyBadge: { qatar: 'داخل قطر', approved: 'منطقة معتمدة', external: 'خارج قطر' },
      weights: 'أوزان التوصيات', w: { rules: 'القواعد', gap: 'فجوات المهارات', peers: 'الزملاء المشابهون', behaviour: 'سلوكك', rating: 'التقييمات' }, ab: 'مقارنة A/B مع القواعد وحدها', abShare: 'نسبة من يرون الهجين ٪', save: 'حفظ', saved: 'حُفظت الإعدادات.',
      stats: 'آخر 30 يومًا', calls: 'استدعاءات', blocked: 'محجوبة', redactions: 'معرّفات أُخفيت', index: 'فهرس المحتوى', chunks: 'مقطع', reindex: 'إعادة الفهرسة', reindexed: 'اكتملت الفهرسة: {{n}} مقطع.', conv: 'نتائج التوصيات', shown: 'عُرضت', clicked: 'نقرات', enrolled: 'تسجيلات', hybrid: 'هجين', rules: 'قواعد فقط',
      logs: 'سجل الاستدعاءات', logsHint: 'لا تُحفظ نصوص الأسئلة أو الإجابات — الأحجام والأزمنة والنتائج فقط.', feature: 'الميزة', status: 'الحالة', reason: 'السبب', ms: 'الزمن', when: 'الوقت',
    },
    common: { loading: 'جارٍ التحميل…', cancel: 'إلغاء', save: 'حفظ', failed: 'تعذّر تنفيذ العملية.', off: 'هذه الميزة غير مفعّلة.' },
  },
}

export const aiEn = {
  aix: {
    nav: { forecasts: 'Forecasts & risks', adaptive: 'Adaptive learning rules' },
    assistant: {
      title: 'Training assistant', subtitle: 'Answers only from platform content and your own data.', open: 'Ask the assistant', close: 'Close', placeholder: 'Ask about your programmes, lessons or schedule…', send: 'Send', thinking: 'Searching the content…',
      empty: 'Ask me about your lessons, programmes, schedule, certificates and hours.', suggestions: ['When is my next session?', 'How many PD hours do I have?', 'What are my certificates?', 'How far am I in my courses?'], sources: 'Sources', helpful: 'Helpful', notHelpful: 'Not helpful', thanks: 'Thanks for the feedback.',
      notSure: 'I am not sure about this answer. You can:', askTrainer: 'Ask the trainer', askSupport: 'Contact support', pickProgram: 'Choose the programme', sentTrainer: 'Your question was sent to the trainer.', sentSupport: 'A support ticket was created.', sourceAi: 'Generated by an AI model', sourceRules: 'Quoted from the content', refused: 'Outside training topics', newChat: 'New chat', history: 'History', error: 'Could not get an answer.',
    },
    rec: { why: 'Why this is suggested', helpful: 'Helpful', dismiss: 'Not for me', dismissed: 'We will not show it for a while.', score: 'Match' },
    feedback: { title: 'Smart feedback on your answers', mistake: 'You chose', right: 'Correct answer', sameMistake: '{{n}}% of colleagues made the same choice', skills: 'Competencies', review: 'What to review', open: 'Open lesson', none: 'No feedback available for this assessment.' },
    draft: {
      title: 'AI feedback draft', hint: 'A draft to help you — no score counts until you accept it.', suggested: 'Suggested score', accept: 'Accept', edit: 'Edit and accept', reject: 'Reject draft', score: 'Score', comment: 'Comment', criteria: 'Criteria', source: { ai: 'AI model', rules: 'Keyword coverage (no model)' },
      status: { draft: 'Draft', accepted: 'Accepted', edited: 'Edited', rejected: 'Rejected' }, saved: 'Score accepted.', rejected: 'Draft rejected.', met: 'Met',
    },
    path: {
      title: 'Your personal path', hint: 'The path adapts to your assessment results.', skipped: 'You skipped this module because you have mastered it', mastery: 'Mastery', remedial: 'Recommended for you', weak: 'Mastery {{m}}% — review this lesson', mastered: 'You mastered {{skill}} ({{m}}%)', none: 'Nothing in your path has changed yet.',
    },
    adaptive: {
      title: 'Adaptive learning rules', hint: 'Skip a module when a competency is mastered; add a remedial lesson when it is weak. Mastery comes from assessment results linked to competencies.', add: 'Add rule', skill: 'Competency', action: 'Action', skip: 'Skip a module (test-out)', addLesson: 'Add a remedial lesson', module: 'Module', lesson: 'Lesson', skipAt: 'Mastery to skip', below: 'Below', save: 'Save rules', saved: 'Rules saved.',
      generate: 'Draft a remedial lesson', generated: 'Draft lesson created — review and publish it in the content editor.', draftNote: 'Generated content stays a draft until the trainer publishes it.', rules: 'Rules', none: 'No rules yet.',
    },
    fc: {
      title: 'Forecasts & risks', subtitle: 'Expected professional-development needs and early-warning lists.', tabs: { forecasts: 'Forecasts', risks: 'Risks' }, dimension: { competency: 'Competencies', job: 'Job titles', school: 'Schools' }, year: 'Year', run: 'Refresh forecasts', ran: 'Forecasts and risk lists refreshed.',
      label: 'Item', value: 'Expected', range: 'Range', confidence: 'Confidence', why: 'Explanation', history: 'History', toPlan: 'Add to plan', pickPlan: 'Choose a draft plan', added: '{{n}} suggested items added to the plan.', none: 'No forecasts yet — run a refresh.',
      risk: { hours_shortfall: 'Hours shortfall', licence_gap: 'Licence about to expire', group_underfill: 'Under-filled group', low_satisfaction: 'Low satisfaction' }, score: 'Severity', resolve: 'Resolved', noRisks: 'No open risks.', reasons: 'Reasons',
    },
    set: {
      title: 'AI & privacy', subtitle: 'Where data is processed, what is hidden, and which model each feature uses.', residency: 'Enforce data residency', residencyHint: 'When on, no personal data goes to a model outside Qatar unless you allow it for a feature (Law 13/2016).',
      redaction: 'Hide personal identifiers before sending', retention: 'Keep logs for (days)', features: { recommendations: 'Recommendations', feedback: 'Assessment feedback', adaptive: 'Adaptive learning', forecasts: 'Forecasting', assistant: 'Trainee assistant' },
      enabled: 'Enabled', model: 'Model', defaultModel: 'Default text model', allowExternal: 'Allow an external model for personal data', usable: 'Ready', notUsable: 'Will use rules only', residencyBadge: { qatar: 'In Qatar', approved: 'Approved region', external: 'Outside Qatar' },
      weights: 'Recommendation weights', w: { rules: 'Rules', gap: 'Skill gaps', peers: 'Similar colleagues', behaviour: 'Your behaviour', rating: 'Ratings' }, ab: 'A/B comparison against rules alone', abShare: 'Share who see the hybrid %', save: 'Save', saved: 'Settings saved.',
      stats: 'Last 30 days', calls: 'Calls', blocked: 'Blocked', redactions: 'Identifiers hidden', index: 'Content index', chunks: 'chunks', reindex: 'Re-index', reindexed: 'Indexing done: {{n}} chunks.', conv: 'Recommendation results', shown: 'Shown', clicked: 'Clicks', enrolled: 'Enrolments', hybrid: 'Hybrid', rules: 'Rules only',
      logs: 'Call log', logsHint: 'Question and answer text is never stored — only sizes, timings and outcomes.', feature: 'Feature', status: 'Status', reason: 'Reason', ms: 'Time', when: 'When',
    },
    common: { loading: 'Loading…', cancel: 'Cancel', save: 'Save', failed: 'That did not work.', off: 'This feature is switched off.' },
  },
}
