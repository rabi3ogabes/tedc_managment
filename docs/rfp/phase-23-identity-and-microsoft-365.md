# Phase 23 — Enterprise identity and Microsoft 365: SAML, app single sign-on, LDAP proven, Teams town halls and activity feed

Closes: `NFR-08 ★` (available once the OpenLDAP job is green), and takes `NFR-07 ★` and `TEC-12 ★` to **Ready, waiting for the Ministry's tenant**.
Prompt: `docs/rfp/closeout/23_IDENTITY_AND_MICROSOFT_365.md`. Guide for the Ministry's administrators: `docs/security/identity-methods.md` (Arabic and English).

## What was built

### SAML 2.0 service provider (NFR-07)
* `onelogin/php-saml` 4.3.2 (with `robrichards/xmlseclibs` 3.1.5), strict mode, behind the same `SsoService` as OpenID Connect: the protocol in force is SAML when
  it is set up, otherwise OpenID Connect. Both finish through `IdentityProvisioner`, so group → role and scope rules, allowed domains and just-in-time accounts
  are shared.
* **Endpoints:** `auth/saml/metadata` (entity id, ACS, SLO, the current signing/encryption key and, during a rollover, the next one), `auth/saml/login` and
  `auth/sso/start` (a signed AuthnRequest, RelayState = a single-use state valid for 10 minutes), `auth/saml/acs`, `auth/saml/slo` (GET and POST).
* **What an answer must satisfy:** the signature of the assertion that is actually used (a signed assertion beside an unsigned smuggled one fails), audience =
  TEDC's entity id, recipient and destination = TEDC's own ACS address (never whatever host a proxy reports), `NotBefore`/`NotOnOrAfter` with at most 180 s of
  skew (181 s refused; 170 s accepted, both edges tested), `InResponseTo` naming a request TEDC made, and a replay register of assertion ids (`sso_replays`, one
  atomic insert-or-ignore so it also holds inside a PostgreSQL transaction). Encrypted assertions are accepted. Logout messages must be signed.
* **Single logout both ways:** signing out sends a signed LogoutRequest (name id and session index kept encrypted in `auth_sessions.sso_meta`); the identity
  provider's signed LogoutRequest ends the matching TEDC sessions; an unsigned one is refused (400).
* **Settings → Integrations → Single sign-on:** TEDC's addresses to copy and the metadata file; the signing key made on the screen (RSA 3072, five years,
  `CN=TEDC SAML`, made with TEDC's own OpenSSL configuration so the subject does not depend on the host) and rolled over safely (publish the next key, the
  identity provider re-reads the metadata, then switch); the identity provider's metadata pasted or fetched (HTTPS, no redirects, 10 s, 500 kB, an address that points inside the network refused, XML with a DOCTYPE or external entity refused), **previewed**
  with every certificate's expiry (amber 30 days before, red when expired) and then applied (audited); `tedc:sso-metadata-refresh` (daily 04:20) re-reads a stored
  address for the **same identity provider only** (another entity id is refused), applies new certificates and addresses (audited
  `saml_idp_metadata_refreshed`) and keeps the outcome for the screen. A certificate the identity provider publishes ahead of a rollover is trusted at once
  because all signing certificates in the metadata are trusted.
* **Test sign-in** (either protocol, also before switching on): an administrator signs in at the identity provider in a new window and sees the claims TEDC
  received, the account it would reach (create / link / linked / refused) and the roles it would grant, with ignored mappings explained (a directory group never
  makes a super administrator). Nobody is signed in and no account is created; the result is readable for 15 minutes by that administrator only.
* **Account takeover:** an existing account is claimed by e-mail, UPN or employee number only when the provider speaks for that account's domain (it is in the
  allowed domains) or marks the e-mail verified (`email_verified` / `xms_edov`); otherwise the sign-in is refused with an explanation. This applies to OpenID
  Connect, SAML and LDAP alike.
* **Single sign-on mandatory** (`sso_mandatory`, replaces `break_glass`; an older setting with break-glass off still means the same): accounts in the allowed
  domains (everyone when none are listed) cannot use a platform password; the super administrator and outside accounts can. The web and app sign-in pages
  read `sso_mandatory` and lead with the Ministry account, keeping the password form one click away.

### Single sign-on in the app (NFR-07)
`flutter_appauth` 12.1.0, system browser (never a web view), PKCE S256: the app opens `auth/sso/start?client=mobile` with its registered address
`qa.edu.tedc.app://sso`, the server finishes OpenID Connect or SAML as for the web and redirects to the app with a **one-time code, valid 90 seconds, bound to the
app's PKCE challenge**; `auth/sso/exchange` needs the verifier, and a wrong or missing verifier burns the code. Only the registered app address is accepted
(`https://…`, look-alike hosts and `tedc://` are refused, so another app registering a similar scheme gains nothing); the web's own redirect allow-list is now the
web address only. Android: `appAuthRedirectScheme` placeholder and the activity without a separate task affinity; iOS: `CFBundleURLTypes`. The button shows when
`public/mobile-config` says `sso`; MFA is the identity provider's; the fingerprint lock keeps working afterwards. Offline learning is untouched.

### LDAP / Active Directory proven (NFR-08)
* `LdapDirectory`: only active accounts (`active_filter`, Active Directory's "not disabled" rule by default), the person's own bind to check the password, then
  the groups read as the service account: `recursive` (default; groups inside groups to depth 10 on any directory), `in_chain` (Active Directory's
  `1.2.840.113556.1.4.1941`, falling back to recursive) or `direct`; each group is offered by its full DN and by its common name; subject `objectGUID`, else
  `entryUUID`, else the DN; filter values escaped (RFC 4515, tested without the extension); a timeout; the directory's certificate authority from the settings and
  certificate verification **always on** (TLS 1.2 or later); a bind failure now says why (LDAP error and TLS diagnostic) on the health card.
* The `ldap` extension is in the Docker image (`install-php-extensions … ldap`); the Security workflow's image scan stayed green with it.
* **CI job `backend-ldap`:** OpenLDAP 1.5.0 (`osixia/openldap`) with LDAPS and STARTTLS and a CA made by the job, a seeded directory
  (`tests/Fixtures/ldap/seed.ldif`: people, groups, a disabled account, a user in nested groups) and random passwords per run. `LdapDirectoryIntegrationTest`
  (group `ldap`; skipped without the extension and server): bind-and-search, wrong password, disabled account, nested groups → roles through `auth/ldap/login`,
  plain LDAP refused, STARTTLS accepted, a certificate for another name refused, an untrusted certificate refused (own process), injection-safe filters, a timeout.
* **Kerberos, certificates and smart cards, FIDO2** are delivered through the identity provider; `docs/security/identity-methods.md` is the matrix (password +
  OTP, TOTP, e-mail/SMS, OIDC, SAML, LDAP, Kerberos via Entra seamless SSO or ADFS, certificates via Entra CBA or ADFS, FIDO2 via Entra with Conditional Access),
  exactly what the Ministry configures for each and how TEDC receives the result, and **mutual TLS client certificates at the API gateway (APIM, Phase 25)** as
  the certificate option for partner systems.

### Microsoft Teams: town halls and the activity feed (TEC-12)
**Source for the town hall API (checked 14 October 2026):** Microsoft Graph **v1.0** `solutions/virtualEvents/townhalls` is generally available (it replaces
Teams live events). From Microsoft Learn: *Create* `POST /solutions/virtualEvents/townhalls` (a **draft**; **delegated `VirtualEvent.ReadWrite` only — application
permissions are not supported**), *Publish* `POST …/{id}/publish`, *Update* `PATCH …/{id}` (`displayName`, `startDateTime`, `endDateTime`, `invitedAttendees`…),
*Cancel* `POST …/{id}/cancel` (permanent), *Sessions* `GET …/{id}/sessions` (the join link), *Attendance reports* `GET …/{id}/sessions/{sid}/attendanceReports[/{rid}/attendanceRecords]`
(`OnlineMeetingArtifact.Read.All`, delegated or application with an application access policy; only an organiser or co-organiser may read records). Activity
feed: `POST /users/{id}/teamwork/sendActivityNotification` (application `TeamsActivity.Send`, or `TeamsActivity.Send.User` with resource-specific consent; the
activity types are declared in the app manifest, manifest version 1.7 or later; a `text` topic needs a `webUrl` on the Teams domain). Because of the first point,
**town halls are created through an organiser account the administrator connects once** (Settings → Integrations → Microsoft Teams → "Connect the organiser
account": authorisation-code sign-in with PKCE, consent asked once, refresh token stored encrypted and rotated, audited `teams_organizer_connected`); meetings keep
using application permissions as before.

* **Town halls:** a session's platform can be "Teams town hall" (`teams_townhall`). Saving creates a draft, publishes it, reads its session for the join link
  (stored as the session's online link) and invites the registered trainees (their Entra object id from their sign-in, otherwise found in the tenant by e-mail);
  a new time or title updates the same event; **cancelling the session, moving it to another platform or to in person cancels the event** (a cancelled town hall is
  permanent, so reinstating makes a new one). Every step is resumable: a draft whose publishing failed is finished by `tedc:teams-sync` (every 10 minutes), which
  also keeps the invitations in line with the registrations (changes are sent only when the list changed). Attendance after the event uses the same duration
  rules as meetings (`ATT-07`: minutes inside the scheduled time, left-and-rejoined, late, minimum presence; a trainer's manual mark is never overwritten).
  Graph errors are retried once with back-off, logged without personal data, shown on the session and never block saving the session; five failures in a row pause
  the integration as for any system.
* **Activity feed:** a new notification channel `teams` (beside push, e-mail and SMS): on when Teams is set up and "send notifications to the activity feed" is on;
  it follows the notification rules (per event, audience, quiet hours), the person's own opt-out for optional kinds (mandatory events ignore it) and the
  delivery report. Only people whose Microsoft identity is known get a notification (no tenant lookups for notifications). Each notification carries a deep link
  `https://teams.microsoft.com/l/entity/<appId>/tedc?webUrl=<TEDC page>&context={"subEntityId":…}`: it opens TEDC's Teams tab, or the web page if the app is not
  installed. A failure is retried (3 tries), recorded on the delivery row with Graph's reason, never delays the other channels, and a refusal of one user's request
  (a 4xx) does not count against the integration's health.
* **Teams app package:** `php artisan tedc:teams-app-package [--out=]` or the card's download: a zip with `manifest.json` (schema 1.29, validated in a test against
  Microsoft's published schema), `ar.json` (Arabic strings), a 192×192 colour icon and a 32×32 transparent outline icon drawn from the emblem, 11 activity types
  (session, attendance, certificate, registration, course, survey, task, programme, account, announcement, other) and a personal tab
  (`/api/v1/teams/tab`, a small branded launcher that opens the portal in the browser; only Teams may frame it, only a path inside TEDC is followed).
* **Graph permissions for the Ministry's admin consent** (the card lists them with a tick for each one the tenant granted, read from the tokens Graph issues):

| Feature | Permission | Type |
|---|---|---|
| Meetings (existing) | `OnlineMeetings.ReadWrite.All` (+ an application access policy for the organiser account) | application |
| Meetings attendance | `OnlineMeetingArtifact.Read.All` | application |
| Town halls: inviting trainees | `User.Read.All` | application |
| Town halls: create, publish, update, cancel | `VirtualEvent.ReadWrite` | delegated (organiser account) |
| Town halls: attendance | `OnlineMeetingArtifact.Read.All` | delegated (organiser account) |
| Teams per group | `Team.Create`, `TeamMember.ReadWrite.All`, `Channel.ReadBasic.All` | application |
| Channel files | `Files.ReadWrite.All` | application |
| Activity feed | `TeamsActivity.Send` | application |

### Screens
* **Settings → Integrations → Single sign-on:** the protocol in force, the app's status and return address, the mandatory switch (with a confirmation that names the
  risks), OpenID Connect and SAML tabs, addresses to copy, key and rollover, metadata preview and apply, certificate expiry warnings, test sign-in and its result window.
* **Settings → Integrations → Microsoft Teams:** connection check, town halls (on, invite-only, the organiser account), the activity feed (switch, app id,
  package download with the steps for the Teams administrator), the tab and redirect addresses and the permissions checklist.
* **Session editor:** "Teams meeting" and "Teams town hall" platforms (also when adding a session) and a Teams status line (event state, invitations, the join
  link, or what stands in the way). **Notification preferences:** a Teams column when the feed is on. **Sign-in pages (web and app):** the mandatory layout.
  **App session screen:** the platform name and a note for town halls; the join link is the session's.
* Help articles `sign-in-ministry-account` (everyone) and `teams-townhalls-and-feed` (administrators).

## Tests
* `SamlSsoTest` (11): a valid signed response signs in, maps roles and exchanges a one-time code; an encrypted assertion; refused — unsigned, tampered, wrong
  audience, wrong recipient/destination, expired beyond 3 minutes, no `InResponseTo`, a wrong request id, a smuggled assertion, not yet valid, a replayed
  assertion, a reused relay state; skew edges accepted; valid metadata; single logout both ways (unsigned refused); key rotation; the login address; e-mail linking
  only for the provider's domains; the test sign-in; the metadata refresh (same provider only, failures kept and audited); internal addresses and external entities refused.
* `MobileSsoExchangeTest` (6): the exchange code is bound to the PKCE verifier, single-use and short-lived; a wrong or missing verifier burns it; only the
  registered address and S256 are accepted; the public configuration says when SSO is offered.
* `IdentitySecurityTest` (12): the existing OIDC/LDAP/break-glass tests, the mandatory switch (and the old setting's meaning), linking by verified e-mail only, the
  OIDC test sign-in, the directory claiming accounts only for allowed domains.
* `LdapFilterTest` (4, no extension) and `LdapDirectoryIntegrationTest` (10, group `ldap`, run by the `backend-ldap` CI job).
* `TeamsTownhallTest` (7): created, published, updated and cancelled with the schedule; a session that leaves Teams takes its event down; invitations follow the
  registrations; attendance by duration rules (manual marks kept); errors retried and logged without blocking; blocked states explained; the organiser account
  connection (state single-use, token never shown, audited). `TeamsActivityFeedTest` (7): the deep link and payload, the channel following rules and opt-outs, retries
  that never block other channels, people without an identity skipped, the app package validated against Microsoft's manifest and localisation schemas (icons
  checked), the tab page's framing and safe path, and the permissions checklist.
* App: `login_sso_button_test` (4) and `sso_sign_in_test` (2).

## Go-live checklist (the Ministry)
1. **Entra ID (OpenID Connect):** app registration (web redirect `…/api/v1/auth/sso/callback`), client secret, `groups` and `email`/`xms_edov` claims, the allowed
   domains and the group map in Settings → Integrations → Entra ID.
2. **SAML (if preferred):** import TEDC's metadata into an Entra enterprise application or ADFS relying party; paste the identity provider's metadata (or its
   address) in the SAML tab; generate TEDC's key first.
3. **Test sign-in** with a normal staff account, an account in each mapped group and an account outside the allowed domains (must be refused); then switch the
   integration on and sign in on the web and in the app; sign out and check single logout; only then switch "single sign-on mandatory" on and keep the break-glass
   password in the Ministry's vault.
4. **The app:** the `qa.edu.tedc.app` scheme is registered in the built app (Android manifest placeholder, iOS `Info.plist`); test on a real phone — not done here.
5. **LDAP / AD (if used):** a read-only service account, LDAPS with the Ministry's CA pasted into `ca_certificate`, the user and active-accounts filters, the group
   map by DN or CN; the Ministry's real Active Directory has not been tried.
6. **Teams:** the app registration's admin consent for the permissions above; an application access policy for the organiser account (meetings); connect the
   organiser account (add the redirect address to the registration; the account needs the licence Microsoft requires for town halls); download the Teams app package,
   upload it in the Teams admin centre, install it for users (app setup policy), compare the app id there with the one in the card; switch the options on, send one
   notification and open its link.

## Limits
* **Nothing was run against a real Entra ID, ADFS, Active Directory or Teams tenant.** SAML and OpenID Connect are proven with test identity providers (the test
  signs assertions with xmlseclibs), Graph with recorded-shape fakes and `Http::fake`. NFR-07 and TEC-12 become Available only after real sign-in and delivery tests.
* LDAP is proven against OpenLDAP, not Active Directory: the `userAccountControl` rule and the in-chain search are the documented AD forms but untested here.
* SAML: only service-provider-initiated sign-in (an unsolicited response is refused on purpose); AuthnRequest and logout over the HTTP-Redirect binding, the
  response over HTTP-POST; no artifact binding.
* The app flow was not run on a device (no Xcode or Android device here); Android compiles in the "Mobile APK" workflow, iOS only has its `Info.plist` checked.
  The redirect is a custom scheme, not an app link: another app registering the same scheme could see the one-time code, which is useless without the verifier.
* The Teams tab is a launcher that opens the portal in the browser: embedding the whole portal needs a framing exception on the web host, which TEDC still refuses.
* The deep link's app id must match the id Teams shows for the uploaded app (the card's field exists to correct it); the activity-feed payload, the tab and the
  town hall flow were built to Microsoft's documentation but not seen in Teams.
* The organiser account is a single point: if its password changes or consent is withdrawn, new town halls stop until it is connected again (the session and card say so).
* Town hall attendance needs the event to be over and its report published by Teams (retried for three days); Graph returns at most the 50 latest reports.
