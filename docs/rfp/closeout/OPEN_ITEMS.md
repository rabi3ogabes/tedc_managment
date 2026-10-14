# Open items: every RFP requirement not yet fully available

Verified in the code on **11 October 2026** (method: `docs/rfp/rfp-comparison-2026-10-10.md`). **278 requirements: 228 available, 50 partial, 0 missing.**
This list is the checklist for the close-out prompt pack in this folder. Each item names the phase that closes it.

**Progress:** Phase 19 done on 11 October 2026 — NTF-09, PLC-03 ★, COL-02, COL-03 and COL-04 are now Available (register: 233 available, 45 partial, 0 missing; mandatory item 25 available).
**Progress:** Phase 20 done on 13 October 2026 — NDS-08, RPT-03 and RPT-11 are now Available (register: 236 available, 42 partial, 0 missing).
**Progress:** Phase 21 done on 13 October 2026 — TYP-12, AI-03 ★ and TEC-08 ★ are now Available; AI-05 ★ is Ready, waiting for the in-country model endpoint and a golden-set run against it (register: 239 available, 39 partial, 0 missing).
**Progress:** Phase 22 done on 13 October 2026 — EXM-10 ★ is now Available as face presence detection (never recognition); mandatory item 27 is available (register: 240 available, 38 partial, 0 missing).
**Progress:** Phase 23 done on 14 October 2026 — NFR-08 ★ is now Available (LDAP proven against OpenLDAP in CI; the identity-methods guide covers Kerberos, certificates, FIDO2 and partner mutual TLS); NFR-07 ★ is Ready, waiting for the Ministry's tenant or identity provider metadata and a real sign-in test (SAML 2.0, OpenID Connect and single sign-on in the app are built and tested with test identity providers); TEC-12 ★ is Ready, waiting for the Ministry's tenant, admin consent, the organiser account and uploading the Teams app (town halls, the activity-feed channel and the app package are built and tested with Graph fakes) (register: 241 available, 37 partial, 0 missing).

**Who unblocks:** *We build it* = software only · *Your decision* = needs your yes/no · *Ministry input* = credentials, specifications or files from the Ministry · *Azure / real run* = needs the Azure subscription or a real test run · *Centre workshops & sign-off* = needs the training centre.

## Summary by phase

| Phase | File | Items | ★ Mandatory |
|---:|---|---|---:|
| 19 ✅ | [19_COLLABORATION_COMPLETION.md](19_COLLABORATION_COMPLETION.md) | NTF-09, PLC-03 ★, COL-02, COL-03, COL-04 — done 11 Oct | 1 |
| 20 ✅ | [20_PLANNING_REPORTS_FORECASTS.md](20_PLANNING_REPORTS_FORECASTS.md) | NDS-08, RPT-03, RPT-11 — done 13 Oct | 0 |
| 21 ✅ | [21_LEARNING_ENGINE_COMPLETION.md](21_LEARNING_ENGINE_COMPLETION.md) | TYP-12, AI-03 ★, AI-05 ★ (Ready), TEC-08 ★ — done 13 Oct | 3 |
| 22 ✅ | [22_EXAM_INTEGRITY_FACE_PRESENCE.md](22_EXAM_INTEGRITY_FACE_PRESENCE.md) | EXM-10 ★ — done 13 Oct | 1 |
| 23 ✅ | [23_IDENTITY_AND_MICROSOFT_365.md](23_IDENTITY_AND_MICROSOFT_365.md) | NFR-07 ★ (Ready), NFR-08 ★, TEC-12 ★ (Ready) — done 14 Oct | 3 |
| 24 | [24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md) | UX-03, TYP-14, CAR-05, ATT-05, CNT-05, NTF-03 ★, UTR-03, PAY-02, TEC-07 ★, TEC-13 ★ | 3 |
| 25 | [25_SECURITY_ENGINEERING_AND_PERFORMANCE.md](25_SECURITY_ENGINEERING_AND_PERFORMANCE.md) | NFR-15 ★, NFR-16 ★, NFR-17 ★, NFR-18 ★, TEC-02 ★, TEC-03 ★ | 6 |
| 26 | [26_AZURE_GO_LIVE_AND_RESILIENCE.md](26_AZURE_GO_LIVE_AND_RESILIENCE.md) | NFR-01 ★, NFR-02 ★, NFR-03 ★, NFR-19 ★, NFR-20 ★, TEC-01 ★, TEC-05 ★, TEC-16 ★ | 8 |
| 27 | [27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md) | UTR-01, EKT-01, NFR-04 ★, DLV-01, DLV-02, DLV-03, DLV-04, DLV-05, DLV-06, DLV-07 | 1 |
| | **Total** | **50** | **26** |

## Missing requirements

None. Every one of the 278 requirements has at least a partial implementation in the code.

## Partial requirements: the full list

### UX · User Interface & Experience — واجهة المستخدم

#### UX-03 — Approved Lusail typeface and readable font sizes
- **Built today:** Lusail is first in every font stack and registered automatically from `web/public/fonts/lusail/` when the licensed files are added (see `lib/fonts.ts`, `public/fonts/lusail-README.md`); falls back to Qatar Sans → Tajawal. Body line height ≥ 1.65. **The Ministry must supply the font files** (web, mPDF, app).
- **Missing:** Lusail files are not in the repository (web, PDF and app are wired to load them).
- **Who unblocks:** Ministry input
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Available as soon as the font files are added

### TYP · Training Types — إدارة أنواع التدريب

#### TYP-12 — Offline learning: watched content offline, sync on reconnect, resume exams
- **Status:** ✅ Available since Phase 21 (13 Oct 2026) — see `docs/rfp/phase-21-learning-engine-completion.md`.
- **Built today:** Offline manifest and idempotent sync done; encrypted downloads and offline exams in the app not built
- **Missing:** No encrypted downloads, offline playback/reading, offline exams or Downloads screen in the app (server manifest and sync exist).
- **Who unblocks:** We build it
- **Closed by:** Phase 21 ([21_LEARNING_ENGINE_COMPLETION.md](21_LEARNING_ENGINE_COMPLETION.md))
- **Expected after the phase:** Available

#### TYP-14 — Integrate external platforms (Coursera, edX, Udemy, LinkedIn Learning) via APIs
- **Built today:** External provider courses become programs with completion sync; needs real provider access
- **Missing:** Only a generic REST adapter and a demo driver; no Coursera/edX/Udemy/LinkedIn-specific drivers.
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Ready; Available after testing with the real system

### CAR · Career Paths & Professional Licences — المسارات التدريبية والترقي الوظيفي

#### CAR-05 — HR integration for experience, grades and appraisals
- **Built today:** HR / Mawared full and delta sync with conflict policy, leaver deactivation and signed inbound changes. Routing profile change requests to HR is not built; needs the HR interface specification (Phase 13).
- **Missing:** Approved profile changes are not routed to HR; no TEDC-vs-HR reconciliation.
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Ready; Available after testing with the real system

### NDS · Needs Assessment & Annual Plan — حصر الاحتياجات وبناء الخطة التدريبية السنوية

#### NDS-08 — Yearly planning rules and program types (ترخيص، تمكين، تمهين، تخصيص، تخيير)
- **Status:** ✅ Available since Phase 20 (13 Oct 2026) — see `docs/rfp/phase-20-planning-reports-forecasts.md`.
- **Built today:** Yearly rules per plan (priority weights, quarter per priority, max seats and hours per group, minimum fill, carry-over) drive generation. Program-type scope, mandatory categories and total seat/hour caps are stored but not yet enforced.
- **Missing:** Programmes have no programme type (the RFP's five types); plan rules for types, mandatory categories and total seat/hour caps are stored but never enforced.
- **Who unblocks:** We build it
- **Closed by:** Phase 20 ([20_PLANNING_REPORTS_FORECASTS.md](20_PLANNING_REPORTS_FORECASTS.md))
- **Expected after the phase:** Available

### ATT · Attendance, Leave & Absence — الحضور والانصراف والاستئذان

#### ATT-05 — Fingerprint attendance-system integration (trainees and trainers)
- **Built today:** Fingerprint gateway (`FingerprintGateway`): signed webhook (generic HTTP and the ZKTeco ADMS ATTLOG push format) and CSV import match punches to the person and the session running in the device's room, ignore duplicates and report unmatched ones; device registry, test and log on `/admin/absence`. A vendor-specific pull SDK needs the Ministry's device model and network access.
- **Missing:** Pull-based fingerprint terminals on the Ministry LAN cannot be reached from the cloud (no edge connector).
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Ready; Available after testing with the real system

### CNT · Content Management & Digital Library — إدارة المحتوى التدريبي

#### CNT-05 — External libraries: Maktabati and Qatar National Library
- **Built today:** External library search (deep link / JSON API) and import; real Maktabati/QNL endpoints to be configured
- **Missing:** Only deep-link/generic JSON drivers; no driver for the libraries' documented interfaces.
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Ready; Available after testing with the real system

### EXM · Exams & Question Banks — إدارة الاختبارات

#### EXM-10 ★ — Anti-cheating: activity tracking, face recognition
- **Status:** ✅ Available since Phase 22 (13 Oct 2026) as face presence detection, not identification — see `docs/rfp/phase-22-exam-integrity.md`.
- **Built today:** Integrity tracking (tab switch, focus loss, full-screen exit, copy/paste, multiple tabs, devtools) with thresholds that flag or auto-submit, live invigilation (flag, extend, void) and optional camera snapshots with the trainee's consent. **Automatic face recognition is not built** — the `face_check` setting is accepted but nothing detects faces; snapshots are reviewed by people.
- **Missing:** Integrity tracking exists, but nothing detects faces; the face_check setting is unused.
- **Who unblocks:** Your decision
- **Closed by:** Phase 22 ([22_EXAM_INTEGRITY_FACE_PRESENCE.md](22_EXAM_INTEGRITY_FACE_PRESENCE.md))
- **Expected after the phase:** Available if you approve face presence; otherwise recorded as declined

### NTF · Notifications & Announcements — إدارة الإشعارات

#### NTF-03 ★ — SMS through the Hudhud system
- **Built today:** Hudhud driver with Arabic UCS-2 encoding, signed delivery-receipt webhook, test button, per-event SMS switches (Phase 11). The request/receipt shape follows a configurable assumption (base URL, path, key or user, receipt secret) and must be matched to the Ministry's Hudhud interface document before go-live.
- **Missing:** The Hudhud driver follows an assumed request shape; not configurable to the real specification without code.
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Ready; Available after testing with the real system

#### NTF-09 — Sound or visual alert on a new notification
- **Status:** ✅ Available since Phase 19 (11 Oct 2026) — see `docs/rfp/phase-19-collaboration-completion.md`.
- **Built today:** Web: pop-up with a soft chime (per-user switch, browser autoplay rules respected) and a pulsing bell; mobile: system push sound and an in-app preference. No custom local-notification sound on mobile yet (Phase 11).
- **Missing:** The web chimes; the app uses the system sound on one channel and stays silent in the foreground; no TEDC sound, no per-user channel.
- **Who unblocks:** We build it
- **Closed by:** Phase 19 ([19_COLLABORATION_COMPLETION.md](19_COLLABORATION_COMPLETION.md))
- **Expected after the phase:** Available

### PLC · Professional Learning Communities — إدارة مجتمعات التعلم المهنية

#### PLC-03 ★ — Votes, polls, open questions, comments, file sharing
- **Status:** ✅ Available since Phase 19 (11 Oct 2026) — see `docs/rfp/phase-19-collaboration-completion.md`.
- **Built today:** Polls, questions, comments and reactions are built; file sharing is by attachment reference (name + link) — no upload widget in the composer yet. Phase 14.
- **Missing:** No file upload anywhere: the API accepts only link references and the web/app composers have no attachment UI at all.
- **Who unblocks:** We build it
- **Closed by:** Phase 19 ([19_COLLABORATION_COMPLETION.md](19_COLLABORATION_COMPLETION.md))
- **Expected after the phase:** Available

### RPT · Reports & Analytics — التقارير والإحصائيات

#### RPT-03 — System-admin reports (employees, courses, paths, lookup, attendance, licence matrix, trainers, results, hours, periodic stats)
- **Status:** ✅ Available since Phase 20 (13 Oct 2026) — see `docs/rfp/phase-20-planning-reports-forecasts.md`.
- **Built today:** Employee data, employee courses, programs-groups, courses by employee number, detailed attendance, programs/licences matrix, trainer follow-up, multi-table achievement statistics, process tracking, trainee results, programs by job category, satisfaction, approved vs actual hours, periodic statistics (Phase 12). Not covered: the 'path' column is the program category, appraisal and licence filters on the employee report, and a separate quarterly view (use month grouping).
- **Missing:** Employee report lacks entity type, workplace, licence, job title, experience and annual-appraisal filters; courses report lacks the real career path; no quarterly statistics.
- **Who unblocks:** We build it
- **Closed by:** Phase 20 ([20_PLANNING_REPORTS_FORECASTS.md](20_PLANNING_REPORTS_FORECASTS.md))
- **Expected after the phase:** Available

#### RPT-11 — Predictive analytics and reports
- **Status:** ✅ Available since Phase 20 (13 Oct 2026) — see `docs/rfp/phase-20-planning-reports-forecasts.md`.
- **Built today:** Forecast and risk pages with explanations and plan hand-off; Excel/PDF export of forecasts is not built. Phase 15.
- **Missing:** Forecast and risk pages cannot be exported to Excel/PDF/Word.
- **Who unblocks:** We build it
- **Closed by:** Phase 20 ([20_PLANNING_REPORTS_FORECASTS.md](20_PLANNING_REPORTS_FORECASTS.md))
- **Expected after the phase:** Available

### UTR · User Training, Help & Support — تدريب المستخدمين والدعم الفني

#### UTR-01 — Role-tailored manuals with screenshots, videos and a downloadable PDF
- **Built today:** Help centre with 28 bilingual articles for 17 roles, contextual «?» panel by page, versioned editor with screenshot/video upload, role manuals as branded PDF (Arabic or English) built from current versions, guided tours, mobile help (`pages/HelpCentre.tsx`, `components/help/*`, `HelpService`, `HelpCentreTest`). **Not done:** real annotated screenshots and screen-recording videos have not been captured; someone must produce them with the centre (see `docs/rfp/phase-18-adoption-help-deliverables.md`).
- **Missing:** No real screenshots or videos in the role manuals.
- **Who unblocks:** We build it
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Available

#### UTR-03 — Support channels (phone, email, Saaed)
- **Built today:** Help centre lists phone, email and Saaed portal with working hours and the P1–P4 service levels; «Report a problem» creates a Saaed ticket and shows its status. **The centre must supply the phone, email and Saaed link** (`TEDC_SUPPORT_PHONE`, `TEDC_SUPPORT_EMAIL`, `TEDC_SUPPORT_SAAED_URL`); until then the page says «Set by the centre».
- **Missing:** Support phone, e-mail and Saaed link come from environment variables only and are empty.
- **Who unblocks:** We build it + Centre workshops & sign-off
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Available once the centre enters its contacts

### EKT · Interactive e-Learning Kits — الحقائب الإلكترونية

#### EKT-01 — Two interactive e-learning kits produced with the centre for phase 1
- **Built today:** Two interactive kits in `backend/resources/ekits/kits.json` (portal use; effective classroom questioning) with trainer/trainee guides and session plan; `tedc:ekits-build` publishes them as e-courses (chapter reading + knowledge checks, final assessment drawn from a question bank, certificate) and exports SCORM 2004 packages in Arabic and English (`EKitsTest`: published and completed end to end as a trainee; manifest parsed). **Not done:** the topics are proposals to agree with the centre; content is a compact first version; no H5P interactions, interactive video, drag-and-drop, captions or xAPI; the package was not run in a real LMS.
- **Missing:** Kits lack interactive video, drag-and-drop, captions and xAPI; never run in a real LMS; topics not agreed with the centre.
- **Who unblocks:** We build it + Centre workshops & sign-off
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Available if the centre agrees the topics

### PAY · Phase 2 · Course Purchasing — المرحلة الثانية · شراء الدورات

#### PAY-02 — Pay through the Ministry e-payment gateway
- **Built today:** Hosted-page redirect with signed server-to-server callback (idempotent), refunds, reconciliation, training gateway; the Ministry gateway's real specification is assumed and has been tested against a mock only. Phase 16.
- **Missing:** The MoE e-pay driver follows an assumed specification; no certification test plan against a sandbox.
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Ready; Available after testing with the real system

### COL · Phase 2 · Collaboration & Knowledge Sharing — المرحلة الثانية · التعاون والتواصل المعرفي

#### COL-02 — Comments and notes on lessons and materials
- **Status:** ✅ Available since Phase 19 (11 Oct 2026) — see `docs/rfp/phase-19-collaboration-completion.md`.
- **Built today:** Private notes and a discussion thread on every lesson; not on individual materials. Phase 14.
- **Missing:** Notes and a discussion thread exist for lessons only; programme materials have neither.
- **Who unblocks:** We build it
- **Closed by:** Phase 19 ([19_COLLABORATION_COMPLETION.md](19_COLLABORATION_COMPLETION.md))
- **Expected after the phase:** Available

#### COL-03 — Content rating and reviews by trainees and trainers
- **Status:** ✅ Available since Phase 19 (11 Oct 2026) — see `docs/rfp/phase-19-collaboration-completion.md`.
- **Built today:** 1–5 stars and reviews with moderation for lessons, materials, kits, library items and programmes (API); the interface is on lessons only. Phase 14.
- **Missing:** The ratings API covers lessons, materials, kits, library items and programmes, but only lessons have a rating screen (web); the app has none.
- **Who unblocks:** We build it
- **Closed by:** Phase 19 ([19_COLLABORATION_COMPLETION.md](19_COLLABORATION_COMPLETION.md))
- **Expected after the phase:** Available

#### COL-04 — File sharing inside discussions
- **Status:** ✅ Available since Phase 19 (11 Oct 2026) — see `docs/rfp/phase-19-collaboration-completion.md`.
- **Built today:** Attachments can be referenced in posts and comments; no upload widget yet. Phase 14.
- **Missing:** Same as PLC-03 inside discussions and comments (up to 5 files per comment).
- **Who unblocks:** We build it
- **Closed by:** Phase 19 ([19_COLLABORATION_COMPLETION.md](19_COLLABORATION_COMPLETION.md))
- **Expected after the phase:** Available

### AI · Phase 2 · Artificial Intelligence — المرحلة الثانية · الذكاء الاصطناعي

#### AI-03 ★ — Adaptive content that adjusts to each learner
- **Status:** ✅ Available since Phase 21 (13 Oct 2026) — see `docs/rfp/phase-21-learning-engine-completion.md`.
- **Built today:** Mastery per competency, skip-module (test-out, audited) and remedial-lesson rules, personal path with reasons, AI-drafted remedial lessons reviewed by the trainer; difficulty of practice draws is not adapted. Phase 15.
- **Missing:** Practice questions do not adapt to mastery; remedial lessons are recommended, not assigned.
- **Who unblocks:** We build it
- **Closed by:** Phase 21 ([21_LEARNING_ENGINE_COMPLETION.md](21_LEARNING_ENGINE_COMPLETION.md))
- **Expected after the phase:** Available

#### AI-05 ★ — ML assistant that answers trainees’ questions
- **Status:** 🟡 Ready since Phase 21 (13 Oct 2026) — waiting for the in-country model endpoint and a golden-set run against it; see `docs/rfp/phase-21-learning-engine-completion.md`.
- **Built today:** Assistant answering from permitted lessons, programmes and library with citations plus the person's own records, declines off-topic, hands over to trainer or support, in-country by default; retrieval uses local hashed embeddings (no pgvector) and generating answers needs a connected model (extractive answers otherwise); not run against a real Azure endpoint. Phase 15.
- **Missing:** No pgvector, no neural embeddings, no provider streaming, no retrieval benchmark; never run against an in-country model.
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 21 ([21_LEARNING_ENGINE_COMPLETION.md](21_LEARNING_ENGINE_COMPLETION.md))
- **Expected after the phase:** Ready; Available after a run on the in-country model

### NFR · Infrastructure, Security & Backup — المتطلبات غير الوظيفية

#### NFR-01 ★ — Secure, scalable, resilient multi-layer HA design (99.9 % SLA)
- **Built today:** HA design and zone-redundant IaC (gateway, Container Apps, PostgreSQL HA, Redis), probes and blue-green release script; nothing deployed or failover-tested yet. Phase 17.
- **Missing:** HA design in IaC only; never deployed or failover-tested.
- **Who unblocks:** Azure / real run
- **Closed by:** Phase 26 ([26_AZURE_GO_LIVE_AND_RESILIENCE.md](26_AZURE_GO_LIVE_AND_RESILIENCE.md))
- **Expected after the phase:** Available after the drill or run with an Azure subscription

#### NFR-02 ★ — Hosting on Azure Qatar (data residency, Law 13/2016)
- **Built today:** Bicep for Azure Qatar Central with a location policy, Azure Blob driver (Azurite-tested), health probes, Azure OpenAI support; not deployed — needs the Ministry's subscription. Phase 17.
- **Missing:** Runs on Vercel/Supabase; Azure Qatar Central IaC never deployed.
- **Who unblocks:** Azure / real run
- **Closed by:** Phase 26 ([26_AZURE_GO_LIVE_AND_RESILIENCE.md](26_AZURE_GO_LIVE_AND_RESILIENCE.md))
- **Expected after the phase:** Available after the drill or run with an Azure subscription

#### NFR-03 ★ — Production, staging (prod-identical) and development environments
- **Built today:** dev / staging / prod parameter files with identical topology and separate vaults and databases; not provisioned. Phase 17.
- **Missing:** dev/staging/prod parameter files only; not provisioned.
- **Who unblocks:** Azure / real run
- **Closed by:** Phase 26 ([26_AZURE_GO_LIVE_AND_RESILIENCE.md](26_AZURE_GO_LIVE_AND_RESILIENCE.md))
- **Expected after the phase:** Available after the drill or run with an Azure subscription

#### NFR-04 ★ — HLD / LLD, bill of materials, sizing and bandwidth design
- **Built today:** HLD, LLD, BOM and sizing, environment matrix written (English with Arabic summaries); full Arabic translation and priced BOM outstanding. Phase 17.
- **Missing:** Architecture docs are English with Arabic summaries; the bill of materials has no prices; no bandwidth design.
- **Who unblocks:** We build it
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Available

#### NFR-07 ★ — Single sign-on and IAM integration
- **Built today:** OpenID Connect single sign-on with Microsoft Entra ID (PKCE, strict token validation, just-in-time accounts, group-to-role mapping with scopes, single logout, break-glass), tested against a mock IdP. SAML 2.0 is not built natively and mobile SSO is not wired; needs the Ministry tenant and app registration (Phase 13).
- **Missing:** No SAML 2.0; no single sign-on in the mobile app; never tested with the Ministry tenant.
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 23 ([23_IDENTITY_AND_MICROSOFT_365.md](23_IDENTITY_AND_MICROSOFT_365.md))
- **Expected after the phase:** Ready; Available after a real sign-in with the Ministry tenant

#### NFR-08 ★ — Auth schemes: AD, LDAP, Kerberos, certificates, tokens, OTP
- **Built today:** LDAP / Active Directory bind-and-search (TLS required) and TOTP, e-mail and SMS one-time codes with recovery codes. Kerberos, certificates and smart-card/FIDO2 are provided by the identity provider (documented, not coded); LDAP needs PHP's ldap extension on the host (Phase 13).
- **Missing:** ldap PHP extension missing from the Docker image; LDAP never tested against a real directory; Kerberos/certificate options undocumented.
- **Who unblocks:** We build it
- **Closed by:** Phase 23 ([23_IDENTITY_AND_MICROSOFT_365.md](23_IDENTITY_AND_MICROSOFT_365.md))
- **Expected after the phase:** Available

#### NFR-15 ★ — VAPT, accredited code review, threat model, risk assessment, security docs
- **Built today:** VAPT readiness checklist, scope document, remediation tracker, threat model and clearance pack contents; the accredited test itself has not been done. Phase 17.
- **Missing:** No ASVS self-assessment against the latest features; no accredited VAPT.
- **Who unblocks:** We build it + Azure / real run
- **Closed by:** Phase 25 ([25_SECURITY_ENGINEERING_AND_PERFORMANCE.md](25_SECURITY_ENGINEERING_AND_PERFORMANCE.md))
- **Expected after the phase:** Pre-VAPT ready; Available after the accredited VAPT

#### NFR-16 ★ — Secure SDLC: secure coding, threat modelling, code analysis
- **Built today:** SSDLC document and pipeline (gitleaks, audits, OSV, Semgrep, Trivy, ZAP baseline template, Dependabot); scans not yet run to a clean result and Larastan max level not reached. Phase 17.
- **Missing:** No Larastan/PHPStan; DAST only in the never-run Azure pipeline; Semgrep warnings not triaged.
- **Who unblocks:** We build it
- **Closed by:** Phase 25 ([25_SECURITY_ENGINEERING_AND_PERFORMANCE.md](25_SECURITY_ENGINEERING_AND_PERFORMANCE.md))
- **Expected after the phase:** Available

#### NFR-17 ★ — API security: encryption, validation, auth, API gateway
- **Built today:** Throttles, WAF policy with a login rate rule and partner-API gateway (APIM) in the IaC; API definitions and quotas for partners not configured. Phase 17.
- **Missing:** No OpenAPI description; API gateway has no API definitions, products or quotas.
- **Who unblocks:** We build it
- **Closed by:** Phase 25 ([25_SECURITY_ENGINEERING_AND_PERFORMANCE.md](25_SECURITY_ENGINEERING_AND_PERFORMANCE.md))
- **Expected after the phase:** Available

#### NFR-18 ★ — Patch and vulnerability management incl. third-party libraries
- **Built today:** Patching SLAs, weekly image rebuild and Dependabot defined; no operating history yet. Phase 17.
- **Missing:** Policy and Dependabot exist but no operating history or SLA tracking of patches.
- **Who unblocks:** We build it
- **Closed by:** Phase 25 ([25_SECURITY_ENGINEERING_AND_PERFORMANCE.md](25_SECURITY_ENGINEERING_AND_PERFORMANCE.md))
- **Expected after the phase:** Available

#### NFR-19 ★ — Enterprise backup and recovery in-country (RPO / RTO)
- **Built today:** PITR 35 d, 7-year monthly vault, blob soft delete/versioning in IaC, DR runbook and monthly restore-test workflow; no restore has been executed. Phase 17.
- **Missing:** Backup design and restore workflow never executed; RPO/RTO not measured. The monthly restore job will fail on schedule until secrets exist.
- **Who unblocks:** Azure / real run
- **Closed by:** Phase 26 ([26_AZURE_GO_LIVE_AND_RESILIENCE.md](26_AZURE_GO_LIVE_AND_RESILIENCE.md))
- **Expected after the phase:** Available after the drill or run with an Azure subscription

#### NFR-20 ★ — Monitoring with real-time alerts
- **Built today:** Probes, action group and alerts (availability, 5xx, p95, queue lag, DB CPU/storage) in IaC; not deployed. Phase 17.
- **Missing:** Alert rules in IaC only; never fired.
- **Who unblocks:** Azure / real run
- **Closed by:** Phase 26 ([26_AZURE_GO_LIVE_AND_RESILIENCE.md](26_AZURE_GO_LIVE_AND_RESILIENCE.md))
- **Expected after the phase:** Available after the drill or run with an Azure subscription

### TEC · Technical Requirements & Integrations — المتطلبات التقنية والتكامل

#### TEC-01 ★ — Stable 24/7
- **Built today:** Designed for 24/7 (zones, probes, autoscale, rollbacks); not proven in operation. Phase 17.
- **Missing:** No operating history on the target platform.
- **Who unblocks:** Azure / real run
- **Closed by:** Phase 26 ([26_AZURE_GO_LIVE_AND_RESILIENCE.md](26_AZURE_GO_LIVE_AND_RESILIENCE.md))
- **Expected after the phase:** Available after 30 days of measured operation on Azure

#### TEC-02 ★ — 10,000 concurrent users; response time < 1.5 s
- **Built today:** k6 scenarios to 10,000 users with p95 < 1.5 s thresholds and a sizing model; the test has NOT been run, so no result is claimed. Phase 17.
- **Missing:** k6 suite never run; no measured capacity.
- **Who unblocks:** We build it + Azure / real run
- **Closed by:** Phase 25 ([25_SECURITY_ENGINEERING_AND_PERFORMANCE.md](25_SECURITY_ENGINEERING_AND_PERFORMANCE.md))
- **Expected after the phase:** Measured locally; Available after the 10,000-user run on Azure staging

#### TEC-03 ★ — 20–30 % yearly user growth without performance loss
- **Built today:** Capacity and cost-growth model for +20–30 % a year with autoscale limits; to be re-run on measured data. Phase 17.
- **Missing:** Growth model not based on measurements.
- **Who unblocks:** We build it + Azure / real run
- **Closed by:** Phase 25 ([25_SECURITY_ENGINEERING_AND_PERFORMANCE.md](25_SECURITY_ENGINEERING_AND_PERFORMANCE.md))
- **Expected after the phase:** Model on measured data; Available after the Azure run

#### TEC-05 ★ — Disaster recovery and business continuity with automation
- **Built today:** DR/BCP runbooks, monthly restore-test and release rollback automation templates; no drill performed. Phase 17.
- **Missing:** DR/BCP runbooks never drilled.
- **Who unblocks:** Azure / real run
- **Closed by:** Phase 26 ([26_AZURE_GO_LIVE_AND_RESILIENCE.md](26_AZURE_GO_LIVE_AND_RESILIENCE.md))
- **Expected after the phase:** Available after the drill or run with an Azure subscription

#### TEC-07 ★ — Ministry integrations: Licences, NSIS, QNEDS, HR / Mawared, AD, Saaed, Sijil, Ministry website
- **Built today:** Integration hub with monitored adapters (health, logs, retries, circuit breaker) for HR, Mawared, licences, NSIS, QNEDS, Saaed, Sijil and the Ministry site. The real system specifications are needed; none was run against the real systems (Phase 13).
- **Missing:** Ministry adapters use assumed specifications; no contracts, mocks, contract tests or go-live checklists.
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Ready; Available after testing with the real system

#### TEC-08 ★ — LTI 1.1 and LTI 1.3 with Deep Linking
- **Status:** ✅ Available since Phase 21 (13 Oct 2026) — see `docs/rfp/phase-21-learning-engine-completion.md`.
- **Built today:** LTI 1.1/1.3 platform with Deep Linking, AGS, NRPS done; TEDC as an LTI tool not built
- **Missing:** TEDC works as an LTI platform only; other LMSs cannot launch TEDC as a tool.
- **Who unblocks:** We build it
- **Closed by:** Phase 21 ([21_LEARNING_ENGINE_COMPLETION.md](21_LEARNING_ENGINE_COMPLETION.md))
- **Expected after the phase:** Available

#### TEC-12 ★ — Advanced Teams: Office 365 forms, structure sync, file sharing, live streaming
- **Built today:** Meetings, attendance by duration, Team per group with membership sync, channel files and Forms results import (CSV export). Live events are link-only and Teams activity-feed notifications are not wired (Phase 13).
- **Missing:** No Teams live events / town halls with attendance; no activity-feed notifications (needs a Teams app package).
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 23 ([23_IDENTITY_AND_MICROSOFT_365.md](23_IDENTITY_AND_MICROSOFT_365.md))
- **Expected after the phase:** Ready; Available after tenant consent and the Teams app upload

#### TEC-13 ★ — Trusted content-provider integration
- **Built today:** Provider adapter framework (generic REST + demo driver); real Coursera/edX/Udemy/LinkedIn APIs need credentials
- **Missing:** Same as TYP-14 (trusted content-provider integration).
- **Who unblocks:** We build it + Ministry input
- **Closed by:** Phase 24 ([24_MINISTRY_SYSTEMS_AND_PROVIDERS.md](24_MINISTRY_SYSTEMS_AND_PROVIDERS.md))
- **Expected after the phase:** Ready; Available after testing with the real system

#### TEC-16 ★ — Automatic patching without user impact
- **Built today:** Revision-based blue-green release with automatic rollback and weekly image rebuilds; not exercised on Azure. Phase 17.
- **Missing:** Blue-green release with rollback never exercised.
- **Who unblocks:** Azure / real run
- **Closed by:** Phase 26 ([26_AZURE_GO_LIVE_AND_RESILIENCE.md](26_AZURE_GO_LIVE_AND_RESILIENCE.md))
- **Expected after the phase:** Available after the drill or run with an Azure subscription

### DLV · Project Deliverables — مخرجات المشروع والمتسلمات

#### DLV-01 — As-Is and To-Be process analysis documents
- **Built today:** As-Is / To-Be document drafted in Arabic with an English copy (`docs/deliverables/01-as-is-to-be.md`): every automated workflow described. **As-Is items are marked ⚑ and need confirming in workshops with the centre.**
- **Missing:** As-Is items unconfirmed (marked ⚑); workshops not held.
- **Who unblocks:** Centre workshops & sign-off
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Ready packs; Available after workshops and sign-off

#### DLV-02 — Needs assessment, scope document, project plan, BRD
- **Built today:** Needs assessment, scope and project plan drafted (`02-needs-scope-plan.md`); BRD generated from the register by `php artisan tedc:deliverables` (`brd.generated.md`: a section per module, acceptance criteria, open items). The needs assessment must be validated with the centre's data.
- **Missing:** Needs assessment, scope and plan are drafts awaiting the centre's review.
- **Who unblocks:** Centre workshops & sign-off
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Ready packs; Available after workshops and sign-off

#### DLV-03 — UX design and system architecture
- **Built today:** UX design and architecture document (`03-ux-and-architecture.md`) linking the Phase 17 HLD/LLD and security docs. It contains no screenshots yet.
- **Missing:** UX/architecture document has no screenshots.
- **Who unblocks:** We build it
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Available

#### DLV-04 — Alpha, Beta and Final releases
- **Built today:** Alpha / Beta / Final gates, exit criteria and sign-off defined (`04-release-management.md`), `CHANGELOG.md`. **No release has been tagged**: tags are cut at each milestone with the centre.
- **Missing:** Release gates defined; no release tagged.
- **Who unblocks:** Centre workshops & sign-off
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Ready packs; Available after workshops and sign-off

#### DLV-05 — User manuals and training & adoption plan
- **Built today:** User manuals = help-centre PDFs per role (always current); training and adoption plan with Train-the-Trainer (`05-training-adoption-plan.md`). Training has not been delivered; manuals have no real screenshots yet.
- **Missing:** Manuals lack screenshots; training sessions not delivered.
- **Who unblocks:** We build it + Centre workshops & sign-off
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Ready packs; Available after workshops and sign-off

#### DLV-06 — Test plan, test reports, bug tracker
- **Built today:** Test plan (`06-test-plan.md`), generated test report and traceability matrix (`test-report.generated.md`, `traceability.generated.md`), UAT script template, bug-tracker conventions aligned to the SLA matrix. **UAT has not been executed, no load test has been run, no independent VAPT yet.**
- **Missing:** No UAT executed, no load test, no VAPT; no automated UAT report; bug-tracker labels not set up.
- **Who unblocks:** We build it + Azure / real run
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Available

#### DLV-07 — Go-live plan, handover report, QA certificate, SLA
- **Built today:** Go-live plan, handover report outline, QA certificate template and the SLA (P1–P4 response and resolution, penalty formula, escalation) drafted (`07-go-live-handover-sla.md`); they are completed with real dates, names and evidence at go-live.
- **Missing:** Go-live plan, handover, QA certificate and SLA are templates without real dates and names.
- **Who unblocks:** Centre workshops & sign-off
- **Closed by:** Phase 27 ([27_ADOPTION_CONTENT_AND_DELIVERABLES.md](27_ADOPTION_CONTENT_AND_DELIVERABLES.md))
- **Expected after the phase:** Ready packs; Available after workshops and sign-off

## Found during the check: not RFP requirements, but they must be fixed

| Finding | Where | Handled in |
|---|---|---|
| **The live site exposes administrator sign-in.** The repository is public, the shared demo password is in 20 tracked files (and in the web bundle), and production has `demo_accounts: true`, so its login page offers one-tap sign-in to every demo account including the super administrator. | `web/src/pages/public/Login.tsx`, `mobile/lib/features/auth/login_screen.dart`, `backend/scripts/vercel-build.php`, workflows, docs | Master prompt §9.0 (do first) |
| Vercel deployments failed for three commits on 10 Oct while CI was green; they recovered on 11 Oct (`2b30bc2` deployed). Keep checking after every push. | Vercel build | Master prompt §9.2 |
| A dated container-scan exception (CVE-2026-78669 in FrankenPHP's Go x/net; expires 10 Nov) needs written risk acceptance or an image upgrade. | `.trivyignore`, `docs/security/patching.md` | Master prompt §9.3 |
| The monthly restore-test workflow will fail on 1 November because the Azure secrets do not exist yet. | `.github/workflows/restore-test.yml` | Master prompt §9.3b, Phase 26 §1.1 |
| The app lets a learner "confirm" a PDF presentation without viewing it, bypassing the minimum-view and minimum-time-per-slide rules. | `mobile/lib/features/course/lesson_screen.dart` (`_SlidesLesson`) | Phase 21, linked fix a |
| The full-screen video rule is enforced on the web only. | App lesson player | Phase 21, linked fix b |
| The `face_check` exam setting is accepted but unused. | `AssessmentController` | Phase 22 |
| Programme category names are classified "Confidential, masked in exports" although they are public reference data. | `config/data_classification.php` | Phase 20 |

