# Phase 13 — Integrations, enterprise identity, Teams, Ministry systems, data migration

Closes: NFR-09, NFR-11, TEC-06, UTR-04, DLV-08 (available); NFR-07, NFR-08, TYP-05, ATT-07, TEC-12, TEC-07, CAR-05 (see "Needs the Ministry").

## 13.1 Integration hub and event bus
`IntegrationManager`/`IntegrationRegistry`: one card per system (Entra, LDAP, Teams, HR, Mawared, licences, NSIS, QNEDS, Saaed, Sijil, Ministry site, Hudhud) with encrypted settings (secrets never returned), health checks (every 15 min), a call log without personal data (30-day retention), retry with back-off and a circuit breaker (five failures in a row pause the system for five minutes and alert administrators once). Every system has a `fake` driver for tests and training (never usable for sign-in in production).
`EventBus`: domain events (`registration.approved|completed`, `certificate.issued`, `attendance.recorded`, `pd.approved`, `licence.updated`, `employee.synced`) go to an outbox and are delivered to subscriptions as signed webhooks (HMAC-SHA256 over `timestamp.body`, five retries 1/5/15/60/360 min, dead-letter, replay); nothing is written while nobody subscribes. Inbound messages (`POST /integrations/{system}/inbound`) must be signed, are rejected when older than five minutes and are applied once per idempotency key. The same outbox is what an Azure Service Bus adapter will read (Phase 17).

## 13.2 Identity
- **Password policy** (length, character classes, history, expiry, optional k-anonymity breach check), **lockout** after N wrong passwords for M minutes (audited, user notified, administrator unlock), password change ends other sessions.
- **Sessions**: a server-side registry with idle timeout and absolute lifetime; an expired, ended or administrator-terminated session is refused at once and its refresh token no longer works (Supabase sessions are adopted into the registry by `session_id`).
- **MFA**: TOTP (RFC 6238 test vector checked), e-mail or SMS (Hudhud) codes (10 min, 5 tries, 1/min), 10 one-time recovery codes, remember-device, enforcement per role (off by default so no one is locked out before e-mail/SMS work), step-up re-verification for privileged actions.
- **Single sign-on**: Entra ID OpenID Connect, authorization code + PKCE, strict ID-token validation (signature from the published keys, issuer, audience, nonce, expiry with one minute of clock allowance), just-in-time accounts, linking by identity / e-mail / UPN / employee number, domain allow-list, group → role mapping with scope (a directory group never grants super administrator), one-time exchange code, single logout, break-glass rule for local passwords.
- **LDAP / AD**: bind-and-search with TLS required and the user's own bind (needs PHP's ldap extension on the host); Kerberos / smart-card / FIDO2 are provided by the identity provider (Entra seamless SSO, ADFS) and are configured there, not coded here.

## 13.3 Microsoft Teams (Graph)
Meetings are created for online sessions (organiser = a service account), updated when the time or title changes and cancelled with the session; the join link becomes the session's online link. Attendance reports are pulled after the session, matched to registrations by e-mail, minutes counted inside the scheduled time (leave and re-join handled), written as `teams` attendance (present/late/absent by a configurable minimum presence) and fed into the attendance percentage; a trainer's manual mark is never overwritten. A Team per training group with members kept in line with registrations and files copied to the channel's SharePoint folder; Forms quizzes linked to assessments and their exported results imported as graded attempts.

## 13.4 Ministry systems
HR / Mawared (full then delta sync, HR wins master data or only fills blanks, leavers deactivated with training records kept, signed inbound changes), licences (read levels, push completions), NSIS (grades and subjects only), QNEDS (aggregated indicators per school), Saaed (the "Report a problem" page and mobile screen, captured context, screenshot, queue and retry, status back by polling and signed webhook, notifications), Sijil (certificates queued on issue and archived with metadata and PDF, retries).

## 13.5 Data migration
See `data-migration.md`.

## Permissions
`integrations.manage`, `integrations.logs`, `webhooks.manage`, `sso.manage`, `security.policy`, `sessions.manage`, `migration.run`. Reporting a problem needs only a signed-in account.

## Needs the Ministry (not verifiable from here)
- **Entra ID**: tenant id, an app registration (client id/secret, redirect URI `…/api/v1/auth/sso/callback`), the group object ids for the role map, allowed e-mail domains.
- **Teams / Graph**: an app registration with application permissions (OnlineMeetings.ReadWrite.All, OnlineMeetingArtifact.Read.All, Team.Create, TeamMember.ReadWrite.All, Files.ReadWrite.All, User.Read.All) plus an application access policy for the organiser account. The code follows the documented Graph v1.0 API and is tested against recorded responses, not a live tenant.
- **HR / Mawared, licences, NSIS, QNEDS, Saaed, Sijil**: the real interface specifications (paths, authentication, field names). The adapters use a documented JSON shape that is easy to adjust; none was run against the real systems. Saaed's ticket and status format in particular is assumed.
- **LDAP**: a host with the ldap extension (most serverless PHP runtimes lack it — an AD-connected deployment, e.g. Azure in Phase 17, or Entra ID instead).

## Known limits
- SAML 2.0 is not implemented natively (use OpenID Connect with Entra, or an IdP that offers it); mobile single sign-on (AppAuth with a custom scheme) is not wired — the app asks for the second factor but signs in with a password; web has the full flow.
- Live events: the link can be stored on a session; creating them through Graph and reading their attendance is not built. Teams activity-feed notifications are not wired.
- HR change requests are not routed to HR when it owns the data; the HR sync overwrites master data under `hr_wins`.
- Sijil receives the standard certificate PDF (not PDF/A).
- Supabase-issued sessions are blocked at once when terminated, but Supabase itself is not told to sign the user out.
