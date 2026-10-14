# Identity methods / طرق التحقق من الهوية

Covers NFR-07 (single sign-on and identity integration) and NFR-08 (Active Directory, LDAP, Kerberos, certificates, tokens,
one-time codes). English first, then Arabic. Last reviewed 14 October 2026 (Phase 23).

---

## English

### How it fits together

TEDC signs people in four ways:

- **its own password with a second factor**;
- **OpenID Connect** (Microsoft Entra ID);
- **SAML 2.0** (Entra ID enterprise app, ADFS, or any SAML 2.0 identity provider);
- **LDAP / Active Directory** username and password.

Kerberos, certificates and smart cards, FIDO2 security keys and passkeys are **methods of the identity provider**. The Ministry
switches them on in Entra ID or ADFS; TEDC then receives an ordinary OpenID Connect or SAML sign-in and does not need to know
which method was used. To require a method for TEDC, the Ministry uses Entra **Conditional Access** (an authentication strength
on the TEDC app) or the ADFS access-control policy of the TEDC relying party.

### The matrix

| Method | Where it runs | What the Ministry configures | What TEDC receives | Status |
|---|---|---|---|---|
| Password + TOTP authenticator app | TEDC | MFA per role (Settings → Security) | — | Available |
| Password + e-mail or SMS code | TEDC (SMS via Hudhud) | MFA per role; the SMS provider (Settings → Notification channels) | — | Available |
| Recovery codes, remembered device, step-up for sensitive actions | TEDC | — | — | Available |
| OpenID Connect | Entra ID | An app registration (see below) | ID token: `oid`, `preferred_username`, `email`, `name`, `groups` (optional `employeeid`, `xms_edov`) | Ready: waiting for the tenant |
| SAML 2.0 | Entra ID, ADFS or another SAML IdP | An enterprise application or relying party trust (see below) | Signed assertion: NameID, object id, e-mail, UPN, names (Arabic and English), employee number, groups | Ready: waiting for IdP metadata |
| LDAP / Active Directory | TEDC → the directory over LDAPS or STARTTLS | A read-only service account and the directory's CA | Attributes: `mail`, `displayName`, `employeeID`, groups (nested included), `objectGUID` | Available (verified with OpenLDAP in CI) |
| Kerberos / Integrated Windows Authentication | Entra seamless SSO, or ADFS | See "Kerberos" below | An ordinary OIDC or SAML sign-in | Configured at the IdP |
| Certificates and smart cards | Entra certificate-based authentication, or ADFS | See "Certificates" below | An ordinary OIDC or SAML sign-in | Configured at the IdP |
| FIDO2 security keys, passkeys, Windows Hello for Business | Entra ID | See "FIDO2" below | An ordinary OIDC or SAML sign-in | Configured at the IdP |
| Single sign-on in the mobile app | System browser (AppAuth, PKCE) → TEDC → the IdP | Nothing beyond OIDC or SAML | The same sign-in; the app gets a one-time code bound to its PKCE verifier | Ready: waiting for the tenant |
| Partner systems (machine identities) | Azure API Management | Client certificates per partner (mutual TLS) | The partner identity, checked at the gateway | Documented; built in Phase 25 |

### OpenID Connect with Entra ID

1. Register an application (single tenant). Redirect URI (web): `https://<api-host>/api/v1/auth/sso/callback`.
2. Add a client secret or certificate.
3. Token configuration:
   - add the **groups** claim (security groups, as object ids);
   - add the optional claims `email`, and `xms_edov` if available;
   - add `employeeid` through a claims-mapping policy if HR numbers should link accounts.
4. In TEDC, go to Settings → Integrations → Entra ID. Enter:
   - the tenant id, client id and secret;
   - the **allowed domains** (for example `moe.gov.qa, edu.gov.qa`);
   - the group map (group object id → role and scope).
5. Run **Test sign-in**. It shows the claims TEDC received, the account it would reach and the roles it would give. Nobody is
   signed in or created by a test.

### SAML 2.0 (Entra ID enterprise application or ADFS)

1. Import TEDC's metadata from `https://<api-host>/api/v1/auth/saml/metadata`, or set the values by hand:

   | Setting | Value |
   |---|---|
   | Identifier (entity ID) | The metadata address above |
   | Reply URL (assertion consumer service, HTTP-POST) | `https://<api-host>/api/v1/auth/saml/acs` |
   | Logout URL (HTTP-Redirect) | `https://<api-host>/api/v1/auth/saml/slo` |

2. Signing:
   - sign the **assertion** (or response and assertion) with SHA-256;
   - logout messages must be signed;
   - assertion encryption is optional: the encryption certificate is in TEDC's metadata.
3. Claims (Microsoft's default names are pre-filled in TEDC, and each can be changed):
   - object identifier;
   - e-mail address;
   - UPN;
   - display name, and an optional Arabic display name;
   - employee number;
   - groups.
4. In TEDC, go to Settings → Integrations → Single sign-on, then paste the IdP metadata or give its HTTPS address.
   - TEDC shows the entity ID, the addresses and each certificate with its expiry before applying.
   - From an address, metadata is **refreshed daily**, for the same identity provider only. A certificate the IdP publishes
     ahead of a rollover is trusted from then on.
5. Generate TEDC's key on the same screen. To rotate it, publish the next key ("next"), let the IdP re-read TEDC's metadata, then
   "promote".
6. Run **Test sign-in**.

### LDAP / Active Directory

- **Connection.** Use LDAPS (port 636, or 3269 for the global catalog) or STARTTLS on another port. Plain LDAP is refused. The
  server certificate is always verified: if the Ministry's CA is not in the server's trust store, paste it into
  "ca_certificate". Give the host as a DNS name the certificate names (not an IP address, and not "localhost": the LDAP library
  compares "localhost" with the machine's own name).
- **Service account.** Read-only, used to find the person; the person's own password is then checked by binding as them.
- **Filters.**
  - User filter: `(sAMAccountName={username})` by default. The value is escaped against filter injection.
  - Active-accounts filter: Active Directory's "not disabled" rule by default. Clear it for directories without one, or use
    their own rule.
- **Groups.**
  - `recursive` (default): groups inside groups, depth 10, on any directory.
  - `in_chain`: Active Directory's transitive search (`1.2.840.113556.1.4.1941`), falling back to recursive.
  - `direct`: the person's own groups only.
  - The group map may name a group by its full DN or by its common name (CN); use the DN when CNs repeat across OUs.
- **Allowed domains.** List them to let the directory claim existing TEDC accounts with those e-mails.
- **Hosting.** The PHP `ldap` extension is in TEDC's container image. A host without it cannot use LDAP; use Entra ID instead.

### Kerberos / Integrated Windows Authentication

- **Entra ID seamless single sign-on.** Enable it in Microsoft Entra Connect; it works with password hash sync or pass-through
  authentication. Add `https://autologon.microsoftazuread-sso.com` to the browsers' intranet zone by Group Policy. Domain-joined
  devices on the network then sign in to TEDC without a password prompt.
- **ADFS.** Enable Windows Integrated Authentication for the intranet in the global authentication policy, and put the ADFS
  host in the intranet zone (Edge and Chrome: `AuthServerAllowlist`).
- TEDC needs no change. Kerberos never reaches TEDC directly; it is how the IdP recognised the person.

### Certificates and smart cards

- **Entra certificate-based authentication.**
  1. Upload the Ministry's root and issuing CA certificates (with their CRL addresses) to Entra ID.
  2. Enable "Certificate-based authentication" in the authentication-methods policy for the staff groups.
  3. Set the username binding, for example the certificate's principal name → `userPrincipalName`.
  4. Set binding rules for single-factor or multi-factor use.
- **ADFS.** Enable certificate authentication in the authentication policy. It uses the TLS client-certificate endpoint on port
  49443, or the `certauth.` host name on 443.
- The person picks the certificate or smart card in the browser prompt. TEDC receives an ordinary sign-in.

### FIDO2 security keys, passkeys and Windows Hello for Business

1. In Entra ID, enable "Passkey (FIDO2)" in the authentication-methods policy. Restricting keys by AAGUID is optional.
2. Create a Conditional Access policy for the TEDC application with the authentication strength **Phishing-resistant MFA**.
3. The same policy can require it only for administrators or only outside the Ministry network.

### Partner systems: mutual TLS (machine identities)

- Partner calls to TEDC's APIs go through **Azure API Management**. The gateway asks for a client certificate on the TEDC custom
  domain and checks it with the `validate-client-certificate` policy:
  - a trusted CA or a pinned thumbprint per partner;
  - validity dates;
  - revocation.
- Only then is the call passed to TEDC, which accepts API traffic from the gateway only.
- OAuth client credentials (Entra) and HMAC-signed webhooks (already in TEDC) stay available for partners that cannot use
  certificates.
- The gateway is built in Phase 25.

### Optional: app links instead of the custom scheme

The app returns to `qa.edu.tedc.app://sso`, which works without any domain. If the Ministry later gives TEDC a verified domain, app links (Android) and universal links
(iOS) are the stronger choice because only the app that owns the domain can receive the address:

- Android: serve `https://<domain>/.well-known/assetlinks.json` with the app's package name (`app.tedcmanagment.vercel`) and the SHA-256 fingerprint of its signing certificate;
  add an `https` intent filter with `android:autoVerify="true"` for the return path.
- iOS: serve `https://<domain>/.well-known/apple-app-site-association` (no extension, `application/json`) listing the bundle id (`qa.edu.tedc.tedcMobile`) and the return
  path; add the Associated Domains capability (`applinks:<domain>`).
- In TEDC add the `https://<domain>/sso` address to the `TEDC_MOBILE_SSO_REDIRECTS` setting (comma separated, next to `qa.edu.tedc.app://sso`); only listed addresses are ever accepted, and the PKCE verifier still binds the code.

### How TEDC links a sign-in to an account

1. **By identity.** The provider's stable id (object id, NameID or `objectGUID`) once linked: e-mail changes do not matter.
2. **Existing account by e-mail, UPN or employee number.** Only when the provider speaks for that account's domain (it is in
   the allowed domains) or marks the e-mail verified. Otherwise the sign-in is refused with an explanation, and nothing is
   linked. This stops a look-alike claim taking over an account.
3. **New account (just-in-time), when allowed.** It gets the employee role; the groups add the mapped roles with their scope.
   A directory group never grants super administrator.

### Single sign-on mandatory, and break-glass

- **The switch.** Settings → Single sign-on → "Single sign-on is mandatory for Ministry staff".
- **Who it applies to.** Accounts in the allowed domains, or every account when none are listed, cannot sign in with a local
  password.
- **What stays.** The super administrator keeps a password (with MFA) as the break-glass account; outside accounts keep their
  own sign-in.
- **Sign-in pages.** The web and app sign-in pages lead with "Sign in with your Ministry account". The password form stays one
  click away for those it applies to.

### Before go-live

1. Apply the IdP metadata (SAML) or the app registration (OIDC). Generate TEDC's SAML key.
2. Run **Test sign-in** with:
   - a normal staff account;
   - an account in each mapped group;
   - an account outside the allowed domains, which must be refused.
3. Switch the integration on. Sign in on the web and in the app.
4. Sign out, and check single logout at the IdP.
5. Only then switch "single sign-on mandatory" on.
6. Keep the break-glass password in the Ministry's vault.

---

## العربية

### الصورة العامة

يدخل المستخدمون إلى المنصة بأربع طرق:

- **كلمة المرور الخاصة بالمنصة مع عامل تحقق ثانٍ**؛
- **OpenID Connect** (‏Microsoft Entra ID)؛
- **SAML 2.0** (تطبيق مؤسسي في Entra ID أو ADFS أو أي مزوّد هوية يدعم SAML 2.0)؛
- **اسم المستخدم وكلمة المرور في LDAP / الدليل النشط**.

أما Kerberos والشهادات والبطاقات الذكية ومفاتيح FIDO2 ومفاتيح المرور فهي **طرق يوفّرها مزوّد الهوية**. تفعّلها الوزارة في Entra
ID أو ADFS، فيصل إلى المنصة دخول عادي عبر OpenID Connect أو SAML، دون حاجة إلى معرفة الطريقة المستخدمة. ولفرض طريقة معيّنة على
المنصة تستخدم الوزارة **الوصول المشروط** في Entra (قوة مصادقة على تطبيق المنصة)، أو سياسة التحكم في الوصول للطرف المعتمِد في ADFS.

### الجدول

| الطريقة | أين تعمل | ما تضبطه الوزارة | ما تستلمه المنصة | الحالة |
|---|---|---|---|---|
| كلمة المرور + تطبيق مصادقة (TOTP) | المنصة | التحقق الثنائي حسب الدور (الإعدادات ← الأمان) | — | متاح |
| كلمة المرور + رمز بالبريد أو الرسائل النصية | المنصة (الرسائل عبر هدهد) | التحقق الثنائي حسب الدور، ومزوّد الرسائل (الإعدادات ← قنوات الإشعارات) | — | متاح |
| رموز الاسترداد، والجهاز الموثوق، والتحقق الإضافي للإجراءات الحساسة | المنصة | — | — | متاح |
| OpenID Connect | ‏Entra ID | تسجيل تطبيق (انظر أدناه) | رمز الهوية: `oid` و`preferred_username` و`email` و`name` و`groups` (واختياريًا `employeeid` و`xms_edov`) | جاهز: بانتظار المستأجر |
| SAML 2.0 | ‏Entra ID أو ADFS أو غيرهما | تطبيق مؤسسي أو ثقة طرف معتمِد (انظر أدناه) | تأكيد موقّع: ‏NameID، والمعرّف، والبريد، وUPN، والاسم بالعربية والإنجليزية، والرقم الوظيفي، والمجموعات | جاهز: بانتظار بيانات المزوّد |
| ‏LDAP / الدليل النشط | المنصة ← الدليل عبر LDAPS أو STARTTLS | حساب خدمة للقراءة فقط، وشهادة جهة إصدار الدليل | السمات: `mail` و`displayName` و`employeeID` والمجموعات (والمتداخلة منها) و`objectGUID` | متاح (تم التحقق مع OpenLDAP في CI) |
| ‏Kerberos / مصادقة Windows المتكاملة | الدخول الموحد السلس في Entra، أو ADFS | انظر «Kerberos» أدناه | دخول عادي عبر OIDC أو SAML | يُضبط لدى المزوّد |
| الشهادات والبطاقات الذكية | المصادقة بالشهادة في Entra، أو ADFS | انظر «الشهادات» أدناه | دخول عادي عبر OIDC أو SAML | يُضبط لدى المزوّد |
| مفاتيح FIDO2 ومفاتيح المرور وWindows Hello for Business | ‏Entra ID | انظر «FIDO2» أدناه | دخول عادي عبر OIDC أو SAML | يُضبط لدى المزوّد |
| الدخول الموحد في تطبيق الجوال | متصفح النظام (AppAuth مع PKCE) ← المنصة ← المزوّد | لا شيء إضافي على OIDC أو SAML | الدخول نفسه؛ يحصل التطبيق على رمز لمرة واحدة مرتبط بمُحقِّق PKCE الخاص به | جاهز: بانتظار المستأجر |
| أنظمة الشركاء (هويات الأنظمة) | ‏Azure API Management | شهادة عميل لكل شريك (TLS المتبادل) | هوية الشريك بعد التحقق منها في البوابة | موثّق؛ يُبنى في المرحلة 25 |

### ‏OpenID Connect مع Entra ID

1. سجّل تطبيقًا (لمستأجر واحد). عنوان إعادة التوجيه (ويب): `https://<api-host>/api/v1/auth/sso/callback`.
2. أضف سرًّا أو شهادة للعميل.
3. إعداد الرموز:
   - أضف مطالبة **groups** (مجموعات الأمان بمعرّفاتها)؛
   - أضف المطالبتين الاختياريتين `email`، و`xms_edov` إن توفرت؛
   - أضف `employeeid` بسياسة ربط المطالبات إن أردتم ربط الحسابات بالرقم الوظيفي.
4. في المنصة: الإعدادات ← التكاملات ← Entra ID. أدخل:
   - معرّف المستأجر ومعرّف العميل والسر؛
   - **النطاقات المسموح بها** (مثل `moe.gov.qa, edu.gov.qa`)؛
   - ربط المجموعات (معرّف المجموعة ← الدور والنطاق).
5. شغّل **تجربة الدخول**. تعرض المطالبات التي وصلت، والحساب الذي سيُفتح، والأدوار التي ستُمنح. لا تُدخِل التجربة أحدًا ولا تُنشئ
   حسابًا.

### ‏SAML 2.0 (تطبيق مؤسسي في Entra ID أو ADFS)

1. استورد بيانات المنصة من `https://<api-host>/api/v1/auth/saml/metadata`، أو أدخل القيم يدويًا:

   | الإعداد | القيمة |
   |---|---|
   | المعرّف (Entity ID) | عنوان البيانات أعلاه |
   | عنوان الرد (خدمة استهلاك التأكيد، HTTP-POST) | `https://<api-host>/api/v1/auth/saml/acs` |
   | عنوان الخروج (HTTP-Redirect) | `https://<api-host>/api/v1/auth/saml/slo` |

2. التوقيع:
   - وقّع **التأكيد** (أو الرد والتأكيد معًا) بخوارزمية SHA-256؛
   - يجب أن تكون رسائل الخروج موقّعة؛
   - تشفير التأكيد اختياري، وشهادة التشفير موجودة في بيانات المنصة.
3. المطالبات (أسماء مايكروسوفت الافتراضية معبّأة مسبقًا في المنصة، ويمكن تغيير كل منها):
   - معرّف الكائن؛
   - البريد الإلكتروني؛
   - ‏UPN؛
   - الاسم المعروض، واسم معروض بالعربية اختياريًا؛
   - الرقم الوظيفي؛
   - المجموعات.
4. في المنصة: الإعدادات ← التكاملات ← الدخول الموحد، ثم الصق بيانات المزوّد أو أدخل عنوانها (HTTPS).
   - تعرض المنصة قبل التطبيق المعرّف والعناوين وكل شهادة مع تاريخ انتهائها.
   - عند إدخال عنوان **تُحدَّث البيانات يوميًا**، ومن المزوّد نفسه فقط. والشهادة التي ينشرها المزوّد قبل التبديل تصبح موثوقة من
     حينها.
5. أنشئ مفتاح المنصة من الشاشة نفسها. للتدوير انشر المفتاح التالي («التالي»)، واترك المزوّد يعيد قراءة بيانات المنصة، ثم
   «اعتمد».
6. شغّل **تجربة الدخول**.

### ‏LDAP / الدليل النشط

- **الاتصال.** استخدم LDAPS (المنفذ 636، أو 3269 للفهرس العام) أو STARTTLS على منفذ آخر. يُرفض LDAP غير المشفّر. ويُتحقق من
  شهادة الخادم دائمًا؛ فإن لم تكن جهة إصدار الوزارة موثوقة على الخادم فالصقها في «ca_certificate». أدخل اسم المضيف كاسم DNS تذكره
  الشهادة (لا عنوان IP ولا «localhost»: تقارن مكتبة LDAP «localhost» باسم الجهاز نفسه).
- **حساب الخدمة.** للقراءة فقط، ويُستخدم للعثور على الشخص، ثم يُتحقق من كلمة مروره بالربط باسمه.
- **المرشّحات.**
  - مرشّح المستخدم: `(sAMAccountName={username})` افتراضيًا، والقيمة محمية من حقن المرشّحات.
  - مرشّح الحسابات الفعّالة: قاعدة «غير معطّل» في الدليل النشط افتراضيًا. أفرغه للأدلة التي لا تملك قاعدة كهذه، أو ضع قاعدتها
    الخاصة.
- **المجموعات.**
  - `recursive` (الافتراضي): المجموعات داخل المجموعات بعمق 10، في أي دليل.
  - `in_chain`: البحث المتعدّي في الدليل النشط (`1.2.840.113556.1.4.1941`)، مع الرجوع إلى `recursive` عند الحاجة.
  - `direct`: مجموعات الشخص المباشرة فقط.
  - يمكن تسمية المجموعة في ربط المجموعات باسمها الكامل (DN) أو اسمها الشائع (CN)؛ استخدم الاسم الكامل إذا تكرر الاسم الشائع في
    أكثر من وحدة تنظيمية.
- **النطاقات المسموح بها.** حدّدها ليتمكن الدليل من ربط حسابات المنصة القائمة التي تحمل تلك العناوين.
- **الاستضافة.** امتداد PHP ‏`ldap` موجود في صورة الحاوية الخاصة بالمنصة. والخادم الذي يخلو منه لا يمكنه استخدام LDAP؛ استخدم
  Entra ID بدلًا منه.

### ‏Kerberos / مصادقة Windows المتكاملة

- **الدخول الموحد السلس في Entra ID.** فعّله في Microsoft Entra Connect، ويعمل مع مزامنة تجزئة كلمات المرور أو المصادقة
  المباشرة. أضف `https://autologon.microsoftazuread-sso.com` إلى منطقة الإنترانت في المتصفحات عبر نهج المجموعة. عندها تدخل أجهزة
  النطاق داخل الشبكة إلى المنصة دون طلب كلمة مرور.
- **‏ADFS.** فعّل مصادقة Windows المتكاملة للإنترانت في سياسة المصادقة العامة، وأضف خادم ADFS إلى منطقة الإنترانت (في Edge
  وChrome: ‏`AuthServerAllowlist`).
- لا تحتاج المنصة إلى أي تغيير. لا يصل Kerberos إلى المنصة مباشرة؛ إنه الطريقة التي تعرّف بها المزوّد على الشخص.

### الشهادات والبطاقات الذكية

- **المصادقة بالشهادة في Entra.**
  1. ارفع شهادتي الجذر والإصدار للوزارة (مع عناوين قوائم الإلغاء) إلى Entra ID.
  2. فعّل «المصادقة بالشهادة» في سياسة طرق المصادقة لمجموعات الموظفين.
  3. اضبط ربط اسم المستخدم، مثلًا اسم الشهادة الرئيسي ← `userPrincipalName`.
  4. اضبط قواعد الربط للاستخدام بعامل واحد أو بعدة عوامل.
- **‏ADFS.** فعّل مصادقة الشهادة في سياسة المصادقة. تستخدم نقطة شهادة العميل على المنفذ 49443، أو اسم المضيف `certauth.` على المنفذ
  443.
- يختار الشخص الشهادة أو البطاقة من نافذة المتصفح، وتستلم المنصة دخولًا عاديًا.

### مفاتيح FIDO2 ومفاتيح المرور وWindows Hello for Business

1. في Entra ID فعّل «مفتاح المرور (FIDO2)» في سياسة طرق المصادقة. تقييد المفاتيح بمعرّف AAGUID اختياري.
2. أنشئ سياسة وصول مشروط لتطبيق المنصة بقوة المصادقة **مصادقة متعددة العوامل مقاوِمة للتصيّد**.
3. يمكن أن تفرضها السياسة نفسها على المسؤولين فقط، أو خارج شبكة الوزارة فقط.

### أنظمة الشركاء: ‏TLS المتبادل (هويات الأنظمة)

- تمر طلبات الشركاء إلى واجهات المنصة عبر **Azure API Management**. تطلب البوابة شهادة عميل على النطاق المخصص للمنصة، وتتحقق منها
  بسياسة `validate-client-certificate`:
  - جهة إصدار موثوقة أو بصمة مثبّتة لكل شريك؛
  - تواريخ الصلاحية؛
  - الإلغاء.
- بعدها فقط يُمرَّر الطلب إلى المنصة، التي لا تقبل طلبات الواجهات إلا من البوابة.
- تبقى بيانات اعتماد العميل في OAuth (‏Entra) والإشعارات الموقّعة بـ HMAC (موجودة في المنصة) متاحة للشركاء الذين لا يستطيعون استخدام
  الشهادات.
- تُبنى البوابة في المرحلة 25.

### اختياري: روابط التطبيق بدل المخطط المخصص

يعود التطبيق إلى `qa.edu.tedc.app://sso` وهو يعمل دون أي نطاق. إذا منحت الوزارة المنصة نطاقًا موثّقًا فروابط التطبيق (أندرويد) والروابط العالمية (iOS)
أقوى لأن التطبيق الذي يملك النطاق وحده يستلم العنوان:

- أندرويد: اعرض الملف `https://<النطاق>/.well-known/assetlinks.json` باسم حزمة التطبيق (`app.tedcmanagment.vercel`) وبصمة SHA-256 لشهادة توقيعه، وأضف نية `https`
  بالخاصية `android:autoVerify="true"` لمسار العودة.
- iOS: اعرض الملف `https://<النطاق>/.well-known/apple-app-site-association` (دون امتداد، من نوع `application/json`) وفيه معرّف الحزمة (`qa.edu.tedc.tedcMobile`)
  ومسار العودة، وأضف قدرة النطاقات المرتبطة (`applinks:<النطاق>`).
- في المنصة أضف العنوان `https://<النطاق>/sso` إلى الإعداد `TEDC_MOBILE_SSO_REDIRECTS` (مفصولًا بفاصلة بجانب `qa.edu.tedc.app://sso`)؛ لا يُقبل أبدًا إلا عنوان مدرج، ويبقى مُحقِّق PKCE رابطًا للرمز.

### كيف تربط المنصة الدخول بالحساب

1. **بالهوية.** المعرّف الثابت لدى المزوّد (معرّف الكائن أو NameID أو `objectGUID`) بعد ربطه أول مرة، فلا يؤثر تغيّر البريد.
2. **حساب قائم بالبريد أو UPN أو الرقم الوظيفي.** فقط إذا كان المزوّد مخوّلًا بنطاق ذلك الحساب (النطاق ضمن النطاقات المسموح بها)، أو
   أكّد أن البريد موثّق. وإلا يُرفض الدخول مع توضيح السبب، ولا يُربط شيء. هذا يمنع الاستيلاء على حساب بمطالبة مشابهة.
3. **حساب جديد (عند السماح بالإنشاء الفوري).** يحصل على دور الموظف، وتضيف المجموعات الأدوار المربوطة بنطاقها. ولا تمنح مجموعة في
   الدليل صلاحية المسؤول الأعلى أبدًا.

### الدخول الموحد الإلزامي وحساب الطوارئ

- **المفتاح.** الإعدادات ← الدخول الموحد ← «الدخول الموحد إلزامي لموظفي الوزارة».
- **على من ينطبق.** لا يستطيع أصحاب الحسابات ضمن النطاقات المسموح بها (أو كل الحسابات إن لم تُحدَّد نطاقات) الدخول بكلمة مرور محلية.
- **ما يبقى.** يحتفظ المسؤول الأعلى بكلمة مرور (مع التحقق الثنائي) كحساب طوارئ، وتحتفظ الحسابات الخارجية بطريقة دخولها.
- **صفحات الدخول.** تُقدِّم صفحتا الدخول في الويب والتطبيق زر «الدخول بحساب الوزارة»، ويبقى نموذج كلمة المرور على بُعد نقرة لمن
  يحق له.

### قبل الإطلاق

1. طبّق بيانات المزوّد (SAML) أو تسجيل التطبيق (OIDC)، وأنشئ مفتاح SAML للمنصة.
2. شغّل **تجربة الدخول** باستخدام:
   - حساب موظف عادي؛
   - حساب في كل مجموعة مربوطة؛
   - حساب من خارج النطاقات المسموح بها (يجب أن يُرفض).
3. فعّل التكامل، ثم جرّب الدخول في الويب والتطبيق.
4. سجّل الخروج، وتحقق من الخروج الموحد لدى المزوّد.
5. بعد ذلك فقط فعّل «الدخول الموحد إلزامي».
6. احفظ كلمة مرور حساب الطوارئ في خزنة الوزارة.
