/** Enterprise identity and Microsoft 365 (Phase 23): single sign-on settings (OpenID Connect and SAML), the test sign-in, and the Teams integration card. */
export const m365Ar = {
  m365: {
    tabs: { sso: 'الدخول الموحد', teams: 'Microsoft Teams' },
    sso: {
      title: 'الدخول الموحد بحساب الوزارة',
      subtitle: 'اختر البروتوكول الذي يدخل به الموظفون بحساب الوزارة، وأدخل بيانات مزوّد الهوية، وجرّب الدخول قبل التشغيل.',
      status: {
        protocol: 'المعمول به الآن', off: 'متوقف', app: 'في تطبيق الجوال', appOn: 'زر «الدخول بحساب الوزارة» ظاهر', appOff: 'الزر غير ظاهر (الدخول الموحد متوقف)',
        appNote: 'يفتح التطبيق متصفح الجهاز بحماية PKCE، ويعود إلى العنوان {{uri}} برمز لمرة واحدة يصلح لتطبيقنا وحده.',
      },
      protocols: { oidc: 'OpenID Connect (‏Entra ID)', saml: 'SAML 2.0' },
      mandatory: {
        title: 'الدخول الموحد إلزامي لموظفي الوزارة',
        hint: 'لا يستطيع موظفو النطاقات المسموح بها (أو الجميع إن لم تُحدَّد نطاقات) الدخول بكلمة مرور المنصة. يبقى المسؤول الأعلى (حساب الطوارئ) والحسابات من خارج الوزارة.',
        needsSso: 'شغّل الدخول الموحد أولًا.',
        confirmOn: 'سيُمنع الموظفون من الدخول بكلمة المرور. هل جرّبت الدخول بحساب موظف عادي وتأكدت أن حساب الطوارئ للمسؤول الأعلى يعمل؟',
        confirmOff: 'سيعود الدخول بكلمة المرور لجميع الحسابات. متابعة؟',
        saved: 'حُفظ الإعداد',
      },
      oidc: {
        title: 'Microsoft Entra ID (‏OpenID Connect)', hint: 'أنشئ تسجيل تطبيق في Entra ID وأدخل بياناته في إعدادات التكامل. أضف عنوان العودة التالي إلى التسجيل:',
        redirect: 'عنوان العودة', settings: 'إعدادات Entra ID', configured: 'التطبيق مسجَّل في الإعدادات', notConfigured: 'لم تُدخَل بيانات التطبيق بعد',
      },
      saml: {
        give: 'أعطِ هذه البيانات لمزوّد الهوية', giveHint: 'استورد ملف البيانات مباشرة، أو انسخ القيم إلى التطبيق المؤسسي (Entra ID) أو الطرف المعتمِد (ADFS).',
        entityId: 'المعرّف (Entity ID)', acs: 'عنوان الرد (ACS، ‏HTTP-POST)', slo: 'عنوان الخروج (SLO)', metadata: 'عنوان بيانات المنصة', download: 'نزّل ملف البيانات',
        copy: 'نسخ', copied: 'نُسخ',
        key: {
          title: 'مفتاح المنصة للتوقيع والتشفير', none: 'لم يُنشأ مفتاح بعد. أنشئه ثم اعطِ بيانات المنصة لمزوّد الهوية.', generate: 'أنشئ المفتاح', next: 'انشر مفتاحًا جديدًا (تدوير)', promote: 'اعتمد المفتاح الجديد',
          current: 'المفتاح الحالي', upcoming: 'المفتاح التالي — منشور في البيانات', rollover: 'للتدوير: انشر مفتاحًا جديدًا، واترك مزوّد الهوية يعيد قراءة بيانات المنصة، ثم اعتمده.',
          created: 'أُنشئ المفتاح', promoted: 'اعتُمد المفتاح الجديد', confirmGenerate: 'إنشاء مفتاح جديد يبدّل المفتاح الحالي فورًا، فيرفض مزوّد الهوية الطلبات حتى يقرأ البيانات الجديدة. للتبديل الآمن استخدم «انشر مفتاحًا جديدًا». متابعة؟',
        },
        idp: {
          title: 'بيانات مزوّد الهوية', applied: 'المطبَّق الآن', none: 'لم تُطبَّق بيانات المزوّد بعد.', entity: 'المعرّف', sso: 'عنوان الدخول', slo: 'عنوان الخروج', certs: 'شهادات التوقيع',
          paste: 'الصق ملف XML', url: 'أو عنوان HTTPS الذي يعرضه', read: 'اقرأ البيانات', preview: 'ما في البيانات', apply: 'طبّق هذه البيانات', done: 'طُبِّقت بيانات المزوّد',
          refresh: { title: 'التحديث اليومي', note: 'إن أدخلت عنوانًا يُحدَّث الملف يوميًا من العنوان نفسه ومن المزوّد نفسه فقط؛ والشهادة التي ينشرها المزوّد قبل التبديل تصبح موثوقة فور نشرها.', ok: 'آخر تحديث ناجح', failed: 'فشل آخر تحديث', never: 'لم يجرِ تحديث بعد' },
        },
        enable: 'تشغيل SAML', enableHint: 'يكتمل الإعداد حين تُطبَّق بيانات المزوّد ويُنشأ المفتاح. جرّب الدخول قبل التشغيل.', ready: 'الإعداد مكتمل', incomplete: 'الإعداد غير مكتمل', advanced: 'المطالبات والمجموعات والنطاقات', saved: 'حُفظ الإعداد',
      },
      cert: { subject: 'الموضوع', notAfter: 'ينتهي في', left: 'بقي {{n}} يومًا', warn: 'ينتهي خلال {{n}} يومًا — جدّده', expired: 'منتهي الصلاحية', fingerprint: 'البصمة (SHA-256)' },
      test: {
        button: 'جرّب الدخول', hint: 'تفتح نافذة للدخول عند مزوّد الهوية ثم تعرض لك ما وصل وما سيفعله TEDC. لا يدخل أحد ولا يُنشأ حساب، ويمكن إجراؤها قبل التشغيل.',
        title: 'نتيجة تجربة الدخول', ok: 'وصلت استجابة سليمة', failed: 'رُفضت الاستجابة', claims: 'ما أرسله مزوّد الهوية', account: 'ما سيفعله TEDC',
        why: { malformed: 'تعذّرت قراءة الاستجابة.', unsolicited: 'الاستجابة لا تخص طلب دخول بدأته المنصة.', invalid: 'التوقيع أو الجمهور أو العنوان أو الوقت غير صحيح.', replayed: 'استُخدم هذا التأكيد من قبل.', no_subject: 'لا يحمل الرمز معرّفًا للمستخدم.' },
        detail: 'التفاصيل',
        fields: { subject: 'المعرّف', email: 'البريد', upn: 'اسم الدخول (UPN)', name: 'الاسم', name_ar: 'الاسم بالعربية', employee_no: 'الرقم الوظيفي', groups: 'المجموعات', email_verified: 'البريد موثَّق', name_id: 'NameID', name_id_format: 'صيغة NameID' },
        outcome: { create: 'سيُنشأ حساب جديد (دور الموظف) بهذه البيانات', link: 'سيُربط بحساب قائم بالبريد نفسه، لأن النطاق موثوق', linked: 'سيدخل إلى الحساب المرتبط بهذه الهوية', refused: 'سيُرفض الدخول' },
        roles: 'الأدوار التي ستُمنح', noRoles: 'لا أدوار إضافية', ignored: 'ربط لن يُنفَّذ', ignoredWhy: { super_admin: 'المجموعات لا تمنح صلاحية المسؤول الأعلى أبدًا', unknown_role: 'الدور غير موجود' },
        user: 'الحساب', close: 'أغلق', closeWindow: 'يمكنك إغلاق هذه النافذة والعودة إلى الإعدادات.', blocked: 'أعدّ بيانات هذا البروتوكول أولًا.',
      },
    },
    teams: {
      title: 'Microsoft Teams', subtitle: 'الاجتماعات واللقاءات المفتوحة وإشعارات موجز النشاط وصلاحيات Microsoft Graph التي يحتاجها كل منها.',
      connection: { title: 'الاتصال', ok: 'استجاب Microsoft Graph', failed: 'تعذّر الاتصال: {{error}}', notConfigured: 'أدخل بيانات التكامل أولًا: المستأجر ومعرّف التطبيق وسرّه.', settings: 'إعدادات التكامل', recheck: 'افحص من جديد', training: 'وضع التدريب (محاكاة)' },
      townhalls: {
        title: 'اللقاءات المفتوحة (Town halls)', hint: 'جلسة تُضبط على «لقاء مفتوح في Teams» يُنشأ لها لقاء ويُنشر ويُدعى إليه المسجَّلون، ويُحتسب الحضور من الوقت داخله.',
        on: 'تفعيل اللقاءات المفتوحة', inviteOnly: 'مقصور على المسجَّلين فقط', saved: 'حُفظ الإعداد',
        organizer: {
          title: 'حساب المنظِّم', connected: 'مرتبط بالحساب {{account}}', none: 'لم يُربط حساب بعد', connect: 'اربط حساب المنظِّم', reconnect: 'أعد الربط', disconnect: 'افصل الحساب',
          why: 'لا يسمح Microsoft للتطبيقات بإنشاء اللقاءات المفتوحة بصلاحية التطبيق وحدها، فتعمل المنصة بحساب منظِّم تربطه مرة واحدة ويُحفظ رمزه مشفَّرًا.',
          redirect: 'أضف عنوان العودة التالي إلى تسجيل التطبيق (Web redirect URI):', done: 'رُبط حساب المنظِّم', failed: 'تعذّر ربط الحساب. تأكد من الموافقة على الصلاحيات وأن الحساب من مستأجر الوزارة.', confirmDisconnect: 'فصل حساب المنظِّم يوقف إنشاء اللقاءات المفتوحة حتى يُربط حساب من جديد. متابعة؟',
        },
      },
      feed: {
        title: 'موجز النشاط في Teams', hint: 'تظهر إشعارات المنصة في موجز النشاط لمن سجّل دخوله بحساب مايكروسوفت، مع رابط يفتح المنصة. تتحكم قواعد الإشعارات في الأنواع التي تصل.',
        on: 'إرسال الإشعارات إلى موجز النشاط', active: 'القناة تعمل', inactive: 'القناة متوقفة', appId: 'معرّف تطبيق Teams', appIdHint: 'يُنشأ مع أول حزمة. بعد رفع التطبيق قارنه بالمعرّف الظاهر في مركز إدارة Teams وصحّحه هنا إن اختلف.',
        package: 'نزّل حزمة تطبيق Teams', version: 'الإصدار {{v}} · {{n}} أنواع من الإشعارات', saved: 'حُفظ الإعداد', downloaded: 'نُزّلت الحزمة',
        steps: ['نزّل الحزمة وسلّمها لمسؤول Teams في الوزارة.', 'يرفعها في مركز إدارة Teams ← إدارة التطبيقات ← رفع تطبيق جديد.', 'يثبّت التطبيق للمستخدمين (سياسة إعداد التطبيقات) كي تصلهم الإشعارات.', 'ثم شغّل الخيار أعلاه وراجع قائمة الصلاحيات.'],
      },
      permissions: {
        title: 'الصلاحيات للموافقة الإدارية', hint: 'أعطِ هذه القائمة لمسؤول Entra ID. تظهر ✓ حين يؤكد فحص Microsoft Graph أن الصلاحية ممنوحة فعلًا.',
        type: { application: 'تطبيق', delegated: 'مفوَّضة (حساب المنظِّم)' }, granted: 'ممنوحة', missing: 'غير ممنوحة', feature: { meetings: 'الاجتماعات', townhalls: 'اللقاءات المفتوحة', teams: 'الفرق', files: 'الملفات', activity_feed: 'موجز النشاط' },
        permission: 'الصلاحية', kind: 'النوع', for: 'لأجل', status: 'الحالة', note: 'تظهر الصلاحيات المفوَّضة بعد ربط حساب المنظِّم.',
      },
      urls: { title: 'عناوين للتسجيل', tab: 'عنوان علامة التبويب', redirect: 'عنوان العودة' },
      session: {
        townhall: 'لقاء مفتوح في Teams', meeting: 'اجتماع Teams', status: 'حالة Teams', invited: '{{n}} مدعوًّا', join: 'رابط الانضمام', hintTownhall: 'يُنشأ اللقاء عند الحفظ ويُدعى إليه المسجَّلون تلقائيًا.',
        blocked: {
          not_configured: 'تكامل Teams غير مفعَّل بعد.', townhalls_off: 'اللقاءات المفتوحة متوقفة في إعدادات Teams.', organizer_not_connected: 'حساب المنظِّم غير مرتبط: اربطه من الإعدادات ← التكاملات ← Microsoft Teams.',
        },
      },
    },
    login: { ministry: 'الدخول بحساب الوزارة', usePassword: 'مسؤول أو حساب من خارج الوزارة؟ ادخل بكلمة المرور', mandatoryNote: 'الدخول بحساب الوزارة هو الطريقة المعتمدة لموظفي الوزارة.' },
    prefs: { teams: 'Teams' },
  },
}

export const m365En: typeof m365Ar = {
  m365: {
    tabs: { sso: 'Single sign-on', teams: 'Microsoft Teams' },
    sso: {
      title: 'Single sign-on with the Ministry account',
      subtitle: 'Choose the protocol staff use to sign in with their Ministry account, enter the identity provider\'s details, and try a sign-in before switching it on.',
      status: {
        protocol: 'In force now', off: 'Off', app: 'In the mobile app', appOn: 'The "Sign in with your Ministry account" button shows', appOff: 'The button is hidden (single sign-on is off)',
        appNote: 'The app opens the device browser with PKCE and returns to {{uri}} with a one-time code that works for this app only.',
      },
      protocols: { oidc: 'OpenID Connect (Entra ID)', saml: 'SAML 2.0' },
      mandatory: {
        title: 'Single sign-on is mandatory for Ministry staff',
        hint: 'Staff in the allowed domains (everyone when none are listed) cannot sign in with a platform password. The super administrator (break-glass) and outside accounts keep theirs.',
        needsSso: 'Switch single sign-on on first.',
        confirmOn: 'Staff will no longer be able to sign in with a password. Have you tried a normal staff sign-in, and checked that the super administrator\'s break-glass account works?',
        confirmOff: 'Password sign-in comes back for every account. Continue?',
        saved: 'Setting saved',
      },
      oidc: {
        title: 'Microsoft Entra ID (OpenID Connect)', hint: 'Create an app registration in Entra ID and enter its details in the integration settings. Add this redirect address to the registration:',
        redirect: 'Redirect address', settings: 'Entra ID settings', configured: 'The app registration is entered in the settings', notConfigured: 'The app registration has not been entered yet',
      },
      saml: {
        give: 'Give these details to the identity provider', giveHint: 'Import the metadata file directly, or copy the values into the enterprise application (Entra ID) or relying party (ADFS).',
        entityId: 'Identifier (Entity ID)', acs: 'Reply address (ACS, HTTP-POST)', slo: 'Logout address (SLO)', metadata: 'Metadata address', download: 'Download the metadata file',
        copy: 'Copy', copied: 'Copied',
        key: {
          title: 'TEDC\'s signing and encryption key', none: 'No key yet. Generate one, then give TEDC\'s metadata to the identity provider.', generate: 'Generate the key', next: 'Publish a new key (rollover)', promote: 'Switch to the new key',
          current: 'Current key', upcoming: 'Next key — published in the metadata', rollover: 'To roll over: publish a new key, let the identity provider re-read TEDC\'s metadata, then switch to it.',
          created: 'Key generated', promoted: 'Switched to the new key', confirmGenerate: 'Generating a key replaces the current one at once, so the identity provider refuses requests until it reads the new metadata. For a safe change use "Publish a new key". Continue?',
        },
        idp: {
          title: 'Identity provider metadata', applied: 'Applied now', none: 'No identity provider metadata applied yet.', entity: 'Entity ID', sso: 'Sign-in address', slo: 'Logout address', certs: 'Signing certificates',
          paste: 'Paste the XML file', url: 'or the HTTPS address that serves it', read: 'Read the metadata', preview: 'What the metadata says', apply: 'Apply this metadata', done: 'Identity provider metadata applied',
          refresh: { title: 'Daily refresh', note: 'With an address, the file is refreshed daily from that address and from the same identity provider only; a certificate it publishes ahead of a rollover is trusted as soon as it appears.', ok: 'Last refresh succeeded', failed: 'Last refresh failed', never: 'Not refreshed yet' },
        },
        enable: 'Switch SAML on', enableHint: 'Setup is complete once the identity provider\'s metadata is applied and the key exists. Try a sign-in before switching on.', ready: 'Setup complete', incomplete: 'Setup incomplete', advanced: 'Claims, groups and domains', saved: 'Setting saved',
      },
      cert: { subject: 'Subject', notAfter: 'Expires', left: '{{n}} days left', warn: 'Expires in {{n}} days — renew it', expired: 'Expired', fingerprint: 'Fingerprint (SHA-256)' },
      test: {
        button: 'Try a sign-in', hint: 'Opens a window to sign in at the identity provider, then shows what arrived and what TEDC would do. Nobody is signed in and no account is created; it works before switching on.',
        title: 'Sign-in test result', ok: 'A valid response arrived', failed: 'The response was refused', claims: 'What the identity provider sent', account: 'What TEDC would do',
        why: { malformed: 'The response could not be read.', unsolicited: 'The response is not for a sign-in the platform started.', invalid: 'The signature, audience, address or time is wrong.', replayed: 'This assertion was already used.', no_subject: 'The token carries no user identifier.' },
        detail: 'Details',
        fields: { subject: 'Identifier', email: 'E-mail', upn: 'Sign-in name (UPN)', name: 'Name', name_ar: 'Arabic name', employee_no: 'Employee number', groups: 'Groups', email_verified: 'E-mail verified', name_id: 'NameID', name_id_format: 'NameID format' },
        outcome: { create: 'A new account (employee role) would be created from these details', link: 'It would be linked to the existing account with the same e-mail, because the domain is trusted', linked: 'They would sign in to the account linked to this identity', refused: 'The sign-in would be refused' },
        roles: 'Roles that would be granted', noRoles: 'No extra roles', ignored: 'Mappings that would not apply', ignoredWhy: { super_admin: 'Groups never grant the super administrator role', unknown_role: 'The role does not exist' },
        user: 'Account', close: 'Close', closeWindow: 'You can close this window and go back to the settings.', blocked: 'Set this protocol up first.',
      },
    },
    teams: {
      title: 'Microsoft Teams', subtitle: 'Meetings, town halls, activity-feed notifications, and the Microsoft Graph permissions each needs.',
      connection: { title: 'Connection', ok: 'Microsoft Graph answered', failed: 'Could not connect: {{error}}', notConfigured: 'Enter the integration details first: the tenant, the app\'s client id and its secret.', settings: 'Integration settings', recheck: 'Check again', training: 'Training mode (simulated)' },
      townhalls: {
        title: 'Town halls', hint: 'A session set to "Teams town hall" gets a town hall that is published and open to the registered trainees; attendance is counted from the time spent in it.',
        on: 'Switch town halls on', inviteOnly: 'Only the registered trainees can join', saved: 'Setting saved',
        organizer: {
          title: 'Organiser account', connected: 'Connected as {{account}}', none: 'No account connected yet', connect: 'Connect the organiser account', reconnect: 'Connect again', disconnect: 'Disconnect',
          why: 'Microsoft does not let apps create town halls with application permissions alone, so TEDC acts as an organiser account you connect once; its token is kept encrypted.',
          redirect: 'Add this redirect address to the app registration (Web redirect URI):', done: 'The organiser account is connected', failed: 'The account could not be connected. Check that the permissions were consented to and that the account belongs to the Ministry tenant.', confirmDisconnect: 'Disconnecting stops new town halls until an account is connected again. Continue?',
        },
      },
      feed: {
        title: 'Teams activity feed', hint: 'TEDC notifications appear in the activity feed of people who signed in with their Microsoft account, with a link that opens TEDC. The notification rules decide which kinds arrive.',
        on: 'Send notifications to the activity feed', active: 'The channel is running', inactive: 'The channel is off', appId: 'Teams app id', appIdHint: 'Created with the first package. After the app is uploaded, compare it with the id shown in the Teams admin centre and correct it here if it differs.',
        package: 'Download the Teams app package', version: 'Version {{v}} · {{n}} kinds of notification', saved: 'Setting saved', downloaded: 'Package downloaded',
        steps: ['Download the package and hand it to the Ministry\'s Teams administrator.', 'They upload it in the Teams admin centre → Manage apps → Upload new app.', 'They install the app for users (app setup policy) so the notifications reach them.', 'Then switch the option above on and check the permissions list.'],
      },
      permissions: {
        title: 'Permissions for admin consent', hint: 'Give this list to the Entra ID administrator. A ✓ shows when a Microsoft Graph check confirms the permission is really granted.',
        type: { application: 'Application', delegated: 'Delegated (organiser account)' }, granted: 'Granted', missing: 'Not granted', feature: { meetings: 'Meetings', townhalls: 'Town halls', teams: 'Teams', files: 'Files', activity_feed: 'Activity feed' },
        permission: 'Permission', kind: 'Type', for: 'For', status: 'Status', note: 'Delegated permissions show once the organiser account is connected.',
      },
      urls: { title: 'Addresses to register', tab: 'Tab address', redirect: 'Redirect address' },
      session: {
        townhall: 'Teams town hall', meeting: 'Teams meeting', status: 'Teams status', invited: '{{n}} invited', join: 'Join link', hintTownhall: 'The town hall is created when you save, and the registered trainees are invited automatically.',
        blocked: {
          not_configured: 'The Teams integration is not on yet.', townhalls_off: 'Town halls are switched off in the Teams settings.', organizer_not_connected: 'The organiser account is not connected: connect it in Settings → Integrations → Microsoft Teams.',
        },
      },
    },
    login: { ministry: 'Sign in with your Ministry account', usePassword: 'Administrator or outside account? Sign in with a password', mandatoryNote: 'The Ministry account is the approved way in for Ministry staff.' },
    prefs: { teams: 'Teams' },
  },
}
