/** TEDC as an LTI tool: the launch from another LMS, linking an account, the focused layout, and the platforms registry (Phase 21). */
export const ltiAr = {
  lti: {
    launch: { opening: 'نفتح المحتوى…', failedTitle: 'تعذّر فتح المحتوى', failed: 'انتهت صلاحية رابط الدخول أو استُخدم من قبل. ارجع إلى منصتك وافتح الرابط مرة أخرى.', signIn: 'تسجيل الدخول إلى شاهين' },
    link: {
      title: 'ربط حسابك بمنصتك', body: 'فتحتَ محتوى شاهين من منصة أخرى ببريدٍ يطابق حسابك. بتأكيدك يُربط حسابك ({{name}}) بهذه المنصة، فتُفتح الدروس منها مباشرة بعد ذلك.',
      note: 'لن يُربط أي حساب دون موافقتك، ويمكن لمسؤول شاهين إلغاء الربط في أي وقت.', confirm: 'نعم، اربط حسابي', cancel: 'ليس الآن',
    },
    embedded: { note: 'فُتح من منصتك التعليمية', full: 'افتح شاهين كاملًا' },
    admin: {
      tab: 'منصات تُطلق شاهين', toolTitle: 'بيانات شاهين كأداة LTI', toolHint: 'أدخل هذه البيانات في نظام إدارة التعلم الآخر ليفتح برامج شاهين ودروسه، ثم سجّل المنصة أدناه.',
      loginUrl: 'رابط بدء الدخول (OIDC)', launchUrl: 'رابط الإطلاق وإعادة التوجيه', deepLinkUrl: 'رابط اختيار المحتوى', jwksUrl: 'مجموعة مفاتيح شاهين (JWKS)', launch11Url: 'رابط الإطلاق LTI 1.1', custom: 'معامل مخصص',
      showKey: 'اعرض المفتاح العام', hideKey: 'أخفِ المفتاح العام', copy: 'انسخ {{what}}', copied: 'نُسخ',
      platforms: 'المنصات المسجلة', add: 'سجّل منصة', empty: 'لم تُسجَّل أي منصة بعد.', status: { active: 'مفعّلة', disabled: 'موقوفة' }, test: 'اختبر الإعداد',
      noPrograms: 'لم يُسمح لها بأي برنامج بعد', launches: '{{n}} إطلاقًا', trusted: 'موثوقة لنطاق {{d}}', deleteConfirm: 'حذف المنصة «{{name}}»؟ لن تستطيع إطلاق شاهين بعد ذلك.', saved: 'حُفظت المنصة.',
      name: 'الاسم', version: 'الإصدار', issuer: 'المُصدِر (Issuer)', clientId: 'معرّف العميل (Client ID)', deployments: 'معرّفات النشر', deploymentsHint: 'افصل بينها بفاصلة.',
      authUrl: 'رابط التفويض في المنصة', tokenUrl: 'رابط الرموز في المنصة', platformJwks: 'مجموعة مفاتيح المنصة (JWKS)', publicKey: 'أو المفتاح العام للمنصة (PEM)', publicKeyHint: 'يكفي أحدهما: رابط المفاتيح أو المفتاح العام.', keepKey: 'اتركه فارغًا لإبقاء المفتاح المحفوظ.',
      consumerKey: 'مفتاح المستهلك', consumerSecret: 'سرّ المستهلك', secretHint: '16 حرفًا على الأقل.', keepSecret: 'اتركه فارغًا لإبقاء السر المحفوظ.', active: 'المنصة مفعّلة',
      reach: 'البرامج التي يمكنها فتحها', reachHint: 'لا تفتح المنصة إلا هذه البرامج ودروسها، ويُسجَّل فيها المتدرب تلقائيًا عند أول إطلاق.', searchPrograms: 'ابحث في البرامج…', chosen: 'اخترت {{n}} برنامجًا',
      accounts: 'الحسابات', trustedDomains: 'نطاقات البريد الموثوقة', trustedHint: 'حساب شاهين الذي يطابق بريده نطاقًا موثوقًا يُربط مباشرة، وغيره لا يُربط إلا بعد تسجيل صاحبه الدخول وموافقته.',
      role: { learner: 'دور المتعلم الجديد', instructor: 'دور المدرّس الجديد' }, roles: { employee: 'متدرب', trainer: 'مدرب' },
      safety: 'لا تسجّل المنصة الدخول أبدًا بحساب يحمل صلاحيات إدارية؛ صاحبه يسجّل الدخول بنفسه.',
      testTitle: 'اختبار إعداد «{{name}}»', checks: { config: 'البيانات مكتملة', jwks: 'مفاتيح المنصة متاحة', token: 'رابط الرموز يستجيب', programs: 'برامج مسموح بها' },
    },
  },
}

export const ltiEn: typeof ltiAr = {
  lti: {
    launch: { opening: 'Opening the content…', failedTitle: 'This content could not be opened', failed: 'The sign-in link has expired or was already used. Go back to your platform and open the link again.', signIn: 'Sign in to Shaheen' },
    link: {
      title: 'Link your account to your platform', body: 'You opened Shaheen content from another platform with an e-mail that matches your account. When you confirm, your account ({{name}}) is linked to that platform and later launches open directly.',
      note: 'No account is ever linked without your consent, and a Shaheen administrator can remove the link at any time.', confirm: 'Yes, link my account', cancel: 'Not now',
    },
    embedded: { note: 'Opened from your learning platform', full: 'Open the full Shaheen' },
    admin: {
      tab: 'Platforms that launch Shaheen', toolTitle: 'Shaheen as an LTI tool', toolHint: 'Enter these details in the other learning management system so it can open Shaheen programmes and lessons, then register the platform below.',
      loginUrl: 'Login initiation URL (OIDC)', launchUrl: 'Launch and redirect URL', deepLinkUrl: 'Content selection URL', jwksUrl: 'Shaheen key set (JWKS)', launch11Url: 'LTI 1.1 launch URL', custom: 'Custom parameter',
      showKey: 'Show the public key', hideKey: 'Hide the public key', copy: 'Copy {{what}}', copied: 'Copied',
      platforms: 'Registered platforms', add: 'Register a platform', empty: 'No platform is registered yet.', status: { active: 'Active', disabled: 'Disabled' }, test: 'Test the setup',
      noPrograms: 'No programme allowed yet', launches: '{{n}} launches', trusted: 'trusted for {{d}}', deleteConfirm: 'Delete the platform "{{name}}"? It will no longer be able to launch Shaheen.', saved: 'The platform was saved.',
      name: 'Name', version: 'Version', issuer: 'Issuer', clientId: 'Client ID', deployments: 'Deployment IDs', deploymentsHint: 'Separate them with commas.',
      authUrl: 'Platform authorisation URL', tokenUrl: 'Platform token URL', platformJwks: 'Platform key set (JWKS)', publicKey: 'Or the platform public key (PEM)', publicKeyHint: 'Either is enough: the key set URL or the public key.', keepKey: 'Leave empty to keep the saved key.',
      consumerKey: 'Consumer key', consumerSecret: 'Consumer secret', secretHint: 'At least 16 characters.', keepSecret: 'Leave empty to keep the saved secret.', active: 'The platform is active',
      reach: 'Programmes it may open', reachHint: 'The platform opens only these programmes and their lessons, and enrols the learner automatically on the first launch.', searchPrograms: 'Search programmes…', chosen: '{{n}} programme(s) chosen',
      accounts: 'Accounts', trustedDomains: 'Trusted e-mail domains', trustedHint: 'A Shaheen account whose e-mail is in a trusted domain is linked straight away; any other is linked only after its owner signs in and agrees.',
      role: { learner: 'Role of a new learner', instructor: 'Role of a new instructor' }, roles: { employee: 'Trainee', trainer: 'Trainer' },
      safety: 'A platform never signs in an account with staff permissions; its owner signs in themselves.',
      testTitle: 'Setup test of "{{name}}"', checks: { config: 'Details complete', jwks: 'Platform keys available', token: 'Token URL answers', programs: 'Programmes allowed' },
    },
  },
}
