# Phase 23 — Enterprise identity and Microsoft 365: SAML, app single sign-on, LDAP proven, Teams live events and activity feed

> Run after Phase 22. All rules in `00_MASTER_PROMPT.md` apply. Skills: `security-review` (mandatory for every auth path), `test-driven-development`, `systematic-debugging`, `mobile-native`, `verification-before-completion`.

## Goal
Every sign-in scheme the RFP lists works and is **proven by tests against real protocol implementations**:
- OpenID Connect and **SAML 2.0** on the web;
- single sign-on **in the mobile app**;
- **LDAP / Active Directory** verified against a real directory server in CI;
- certificates and Kerberos through the identity provider, documented and configurable.

Teams gains **live events (town halls)** with attendance, and TEDC notifications can land in the **Teams activity feed**. When the Ministry hands over its tenant, going live is configuration plus one test run.

## Closes
`NFR-07 ★` single sign-on and IAM integration · `NFR-08 ★` AD, LDAP, Kerberos, certificates, tokens, OTP · `TEC-12 ★` advanced Teams (Forms, structure sync, file sharing, **live streaming**).

## What exists today (verified 11 Oct 2026)
- **OIDC with Entra ID.** `backend/app/Security/Sso/{SsoService,OidcClient,IdentityProvisioner}.php` and `App\Models\SsoState` provide PKCE, strict token validation, just-in-time accounts, group-to-role mapping with scopes, single logout and break-glass. Routes: `auth/sso/start`, `auth/sso/callback`, `auth/sso/exchange` (`routes/api.php` ~312). Tested against a mock identity provider.
- **SAML 2.0 is not implemented.**
- **App sign-on.** The app (`mobile/lib/features/auth/login_screen.dart`) signs in with a password plus MFA. **There is no SSO in the app** (no AppAuth or system-browser flow).
- **LDAP.** `app/Integrations/Identity/{DirectoryClient,LdapDirectory,FakeDirectory,DirectoryLogin}.php` (bind-and-search, TLS required) and `POST auth/ldap`. **The PHP `ldap` extension is not in the Docker image** (`Dockerfile` line ~30 installs `pdo_pgsql pgsql intl gd zip bcmath opcache pcntl`). Nothing is tested against a real directory.
- **Kerberos, certificates and smart cards** are "provided by the identity provider" in `docs/rfp/phase-13-integrations-identity.md`, but there is no configuration guide or test.
- **Teams.** `app/Integrations/Teams/{GraphApi,HttpGraph,FakeGraph,TeamsService}.php` cover meetings (create/update/delete), attendance by duration, a Team per group with membership sync, channel file upload and Forms results import. **Missing:** live events / town halls (creation and attendance) and **activity-feed notifications** (they need a Teams app manifest).

## 1. SAML 2.0 service provider (NFR-07)
- Use a maintained library (`onelogin/php-saml` 4.x, which brings `robrichards/xmlseclibs`). Pin it and record it in `docs/security/third-party-register.md`. It sits beside OIDC behind the same `SsoService` facade: `protocol = oidc | saml` per identity-provider configuration.
- **Endpoints:**
  - `GET auth/saml/metadata`: SP metadata with the entity ID, ACS and SLO URLs, the signing and encryption certificate, NameID formats;
  - `GET auth/saml/login`: AuthnRequest, signed, with RelayState carrying a single-use state;
  - `POST auth/saml/acs`: validate the signature (response and/or assertion), audience, recipient, `NotOnOrAfter`/`NotBefore` with skew, `InResponseTo`, and a replay cache for assertion IDs; decrypt encrypted assertions;
  - `GET|POST auth/saml/slo`: single logout both ways.
- **Attribute mapping** (email, UPN, display names in Arabic and English, employee number, groups) goes through the **existing** `IdentityProvisioner`, so the group → role and scope rules are shared with OIDC.
- **Settings → Integrations → Single sign-on:** pick the protocol and upload or paste the IdP metadata (or its URL with periodic refresh). The SP key and certificate are generated and rotated from the screen and stored encrypted. "Test sign-in" and a health card.
- **Break-glass local login** stays exactly as today.

## 2. Single sign-on in the app (NFR-07)
- **Flow:** use `flutter_appauth` (system browser, PKCE), not a WebView.
  1. The app opens `auth/sso/start?client=mobile` with the custom redirect `qa.edu.tedc.app://sso`. Today's ids are Android `applicationId = app.tedcmanagment.vercel` and iOS `qa.edu.tedc.tedcMobile`; the scheme is independent of them, but check that it does not clash.
  2. The server finishes OIDC or SAML exactly as for the web.
  3. The server redirects to the app scheme with a **one-time, short-lived exchange code** bound to the PKCE verifier.
  4. The app calls `POST auth/sso/exchange` and receives TEDC tokens.
- **Platform setup:** register the scheme on Android (intent filter) and iOS (`CFBundleURLTypes`). Use app links/universal links if a verified domain is available (document the `assetlinks.json` / `apple-app-site-association` files).
- **In the app:** a "Sign in with your Ministry account" button on the login screen, shown when SSO is configured (from `public/mobile-config`). MFA is handled by the IdP; the app's biometric unlock keeps working after SSO.

## 3. LDAP / AD proven, Kerberos and certificates (NFR-08)
- Add `ldap` to `install-php-extensions` in the `Dockerfile`. Check that the image still builds and that the Trivy scan stays clean (see the CVE note in the master prompt).
- **CI job `backend-ldap`:** a service container `bitnami/openldap` with LDAPS (self-signed CA mounted) and a seeded directory (users, groups, a disabled account, a user in nested groups). The job runs PHP **with** the ldap extension and `php artisan test --group=ldap`. Tests:
  - bind-and-search succeeds;
  - a wrong password fails;
  - a disabled account fails;
  - group → role mapping works, including nested groups via `memberOf` / matching-rule-in-chain where the server supports it, with a documented fallback otherwise;
  - TLS required: plain LDAP is refused;
  - injection-safe filter escaping;
  - a timeout is handled.
- **Kerberos / Integrated Windows Authentication and certificate-based sign-in** are delivered **through the identity provider** (Entra certificate-based authentication / ADFS with Windows Integrated Authentication). Write `docs/security/identity-methods.md` (Arabic and English): the supported matrix (password+OTP, TOTP, e-mail/SMS OTP, OIDC, SAML, LDAP, Kerberos via ADFS/Entra seamless SSO, certificate via Entra CBA or ADFS, FIDO2 via Entra), exactly what the Ministry configures for each, and how TEDC receives the result (claims). Add a **settings switch** that hides local password login for staff when SSO is mandatory (break-glass excepted).
- **Partner APIs (machine identities):** document mutual-TLS client certificates at the API gateway (APIM, Phase 25) as the certificate option for system-to-system calls.

## 4. Teams live events and activity feed (TEC-12)
- **Live events / town halls.** Teams "live events" are being replaced by **town halls** (Microsoft Graph `solutions/virtualEvents/townhalls`). Confirm the current Graph API status in Microsoft's documentation before coding and record the source in the phase doc.
  - Extend `GraphApi` with `createTownhall`, `updateTownhall`, `cancelTownhall` and `townhallAttendance` (or the documented equivalents) in `HttpGraph` and `FakeGraph`.
  - A session's mode gains `teams_townhall`: creating, rescheduling or cancelling the session keeps the event in sync; registered trainees are added as attendees; attendance after the event feeds the same duration-based attendance as meetings (`ATT-07` rules).
- **Activity feed notifications:**
  - Generate a **Teams app package** (`manifest.json` with `webApplicationInfo` and `activities.activityTypes` for TEDC events, the colour and outline icons from the brand) through `php artisan tedc:teams-app-package`, producing a zip for the Ministry's Teams admin to upload.
  - Add a **`teams` notification channel** in `DeliveryPolicy`: for users with a known Entra object id, `POST /users/{id}/teamwork/sendActivityNotification` with a deep link back to TEDC.
  - Per-event switches use the existing notification rules. Failures are logged and retried; they never block the other channels.
- **Graph permissions:** list in the phase doc exactly the application permissions needed for each feature (meetings, town halls, teams, files, activity feed), for the Ministry's admin consent.

## 5. Web and app screens
- **Single sign-on settings:** OIDC/SAML tabs, a metadata upload with parsed preview (entity ID, certificates with expiry dates and a warning 30 days before), test sign-in, and the mobile SSO status.
- **Teams integration card:** town halls on/off, "Download Teams app package", the activity-feed switch and a permissions checklist (✓ when a Graph probe confirms each permission).
- **Session editor:** a "Teams town hall" mode next to "Teams meeting".
- **App:** the SSO button and the town-hall join link on the session screen.

## 6. Tests (write first)
- **`SamlSsoTest`** with a test IdP: generate a key pair in the test and sign assertions with xmlseclibs.
  - A valid signed response signs the user in and maps roles.
  - These are each refused: an unsigned or tampered response, a wrong audience, a wrong recipient, an expired assertion, a replayed assertion ID, a missing `InResponseTo`.
  - An encrypted assertion is accepted; SLO works both ways; the metadata endpoint is valid XML.
- **`MobileSsoExchangeTest`:** the exchange code is single-use, short-lived and PKCE-bound; a wrong verifier is refused; the app redirect allow-list is enforced (no open redirect).
- **`LdapDirectoryIntegrationTest`** (group `ldap`; runs in the new CI job): see §3.
- **`TeamsTownhallTest`** (`Http::fake` for Graph): create, update and cancel follow the session; attendance maps to duration rules; Graph errors are retried and logged.
- **`TeamsActivityFeedTest`:** the channel is chosen per rule, the payload includes a deep link, failures don't block other channels, and the generated app package zip validates against the manifest schema.

## Review focus
1. **XML signature wrapping** and other SAML attacks. Use the library's strict mode; never pick the first assertion without checking that it is the signed one.
2. **Clock skew.** Allow at most ±3 minutes and test the edges.
3. **Open redirects** in RelayState and the mobile redirect.
4. **Account takeover by email match** in JIT provisioning (same rule as OIDC: link only with a verified claim and a trusted IdP).
5. **The ldap extension in the image** must not reintroduce a HIGH CVE (check Trivy).

## Definition of Done
- **NFR-08:** LDAP proven against OpenLDAP in CI, the extension is in the image, and the identity-methods guide is written (Kerberos and certificates via the IdP, mTLS for partners). → **Available** (evidence: "verified with OpenLDAP in CI; the Ministry's AD connection is a go-live checklist item").
- **NFR-07:** OIDC + SAML 2.0 (web) and app SSO are built and tested with test IdPs. → **Partial — Ready, waiting for:** the Ministry's Entra ID tenant / IdP metadata and the app registration. It becomes Available after a real sign-in test.
- **TEC-12:** town halls with attendance, an activity-feed channel and the Teams app package are built and tested with Graph fakes. → **Partial — Ready, waiting for:** the Ministry's tenant, admin consent for the listed Graph permissions, and uploading the Teams app.
- The gate is green (including the new `backend-ldap` CI job), `docs/rfp/phase-23-identity-and-microsoft-365.md` (with the go-live checklist) is written, the register is updated and the change is pushed with everything green.
