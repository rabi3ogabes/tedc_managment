# TEDC × RFP — what is built and what is not

Checked 10 October 2026 against the Educational Training Portal RFP (مشروع بوابة التدريب التربوي, May 2026), the gap analysis of 5 October 2026 and the 19 implementation prompts. Every requirement was checked in the code (routes, tables, screens, services and tests), not in the documentation.

## The answer

- **228 of 278 requirements are available, 50 are partial and none are missing.** On 5 October it was 71 available, 89 partial and 118 missing. Weighted coverage (available = 1, partial = ½) went from 41.5% to 91.0%.
- **Mandatory (knock-out) items:** 21 of the 25 software items are met and 4 are partial. Items 1–6 are vendor qualifications, answered in the proposal. On 5 October: 5 met, 14 partial, 6 missing.
- **Of the 50 partial items, 11 are software we can finish now, without the Ministry.** One more (face recognition in exams) needs your decision. The other 38 wait on the Ministry (15), on the Azure environment and real test runs (14), or on documents to finish with the centre (9).
- **This check found and fixed 5 real defects.** Four are settings that admins could save but that did nothing; the fifth is a lesson-page bug found while testing the player. It also corrected the project’s own register, which overstated one item and understated 25.

## What is not done yet — and who has to act

### Software we can finish now (11)

No Ministry input needed. Each is a contained piece of work on top of what already exists.

- **TYP-12** Offline learning: watched content offline, sync on reconnect, resume exams — Encrypted offline downloads and offline exams in the app (offline manifest and sync are done).
- **NDS-08** Yearly planning rules and program types (ترخيص، تمكين، تمهين، تخصيص، تخيير) — Enforce the stored program-type scope, mandatory categories and total seat/hour caps during plan generation.
- **NTF-09** Sound or visual alert on a new notification — A custom notification sound in the app (web already chimes; app uses the system sound).
- **PLC-03** ★ Votes, polls, open questions, comments, file sharing — An upload button in community posts and comments (files are shared by link today).
- **RPT-03** System-admin reports (employees, courses, paths, lookup, attendance, licence matrix, trainers, results, hours, periodic stats) — Appraisal and licence filters on the employee report and a separate quarterly view.
- **RPT-11** Predictive analytics and reports — Excel / PDF export of the forecast and risk pages.
- **COL-02** Comments and notes on lessons and materials — Comments on individual materials (lessons already have notes and a thread).
- **COL-03** Content rating and reviews by trainees and trainers — Rating screens for materials, kits, library items and programmes (the API supports them; the screen is on lessons only).
- **COL-04** File sharing inside discussions — Same upload button as PLC-03, inside discussions.
- **AI-03** ★ Adaptive content that adjusts to each learner — Adapt the difficulty of practice questions to each learner.
- **TEC-08** ★ LTI 1.1 and LTI 1.3 with Deep Linking — Let TEDC itself be launched as an LTI tool from another platform (TEDC as LTI platform is done).

### Needs your decision (1)

Technically possible, but it touches biometric data, so it should be a deliberate choice.

- **EXM-10** ★ Anti-cheating: activity tracking, face recognition — Automatic face detection during exams. It can run inside the browser so no image leaves the device, but camera-based checks are biometric processing under Qatar’s data-protection law.

### Waiting on the Ministry (15)

The code is built and tested against mocks. It needs the Ministry’s files, credentials or interface documents to go live.

- **UX-03** Approved Lusail typeface and readable font sizes — The licensed Lusail font files (web, PDF, app). Everything is wired to load them.
- **TYP-14** Integrate external platforms (Coursera, edX, Udemy, LinkedIn Learning) via APIs — Coursera / edX / Udemy / LinkedIn Learning API credentials.
- **CAR-05** HR integration for experience, grades and appraisals — HR interface specification for sending profile changes to HR.
- **ATT-05** Fingerprint attendance-system integration (trainees and trainers) — The fingerprint device model and network access for a vendor pull SDK (push webhook and CSV import work).
- **CNT-05** External libraries: Maktabati and Qatar National Library — Maktabati and Qatar National Library endpoints.
- **NTF-03** ★ SMS through the Hudhud system — Hudhud SMS interface document and credentials to match the driver.
- **UTR-03** Support channels (phone, email, Saaed) — The centre’s support phone, e-mail and Saaed link.
- **EKT-01** Two interactive e-learning kits produced with the centre for phase 1 — Agree the two e-kit topics with the centre and enrich them (video, drag-and-drop, captions).
- **PAY-02** Pay through the Ministry e-payment gateway — The Ministry e-payment gateway specification (tested against a mock).
- **AI-05** ★ ML assistant that answers trainees’ questions — An in-country model endpoint (Azure OpenAI, Qatar) and a vector store; answers are extractive until then.
- **NFR-07** ★ Single sign-on and IAM integration — Entra ID tenant and app registration; SAML and app sign-in follow.
- **NFR-08** ★ Auth schemes: AD, LDAP, Kerberos, certificates, tokens, OTP — AD / LDAP host details; Kerberos and certificates come through the identity provider.
- **TEC-07** ★ Ministry integrations: Licences, NSIS, QNEDS, HR / Mawared, AD, Saaed, Sijil, Ministry website — Interface specifications and access for Licences, NSIS, QNEDS, HR / Mawared, Saaed, Sijil and the Ministry website.
- **TEC-12** ★ Advanced Teams: Office 365 forms, structure sync, file sharing, live streaming — Teams tenant and Graph permissions; live events and activity-feed notices are wired after that.
- **TEC-13** ★ Trusted content-provider integration — Same provider credentials as TYP-14.

### Needs the Azure environment and real runs (14)

Designed and written as code. Proof comes only from deploying it and running the drills, tests and audits.

- **NFR-01** ★ Secure, scalable, resilient multi-layer HA design (99.9 % SLA) — Deploy the zone-redundant design and test failover.
- **NFR-02** ★ Hosting on Azure Qatar (data residency, Law 13/2016) — Deploy to the Ministry’s Azure Qatar Central subscription.
- **NFR-03** ★ Production, staging (prod-identical) and development environments — Provision dev, staging and production from the parameter files.
- **NFR-15** ★ VAPT, accredited code review, threat model, risk assessment, security docs — An accredited penetration test (VAPT).
- **NFR-16** ★ Secure SDLC: secure coding, threat modelling, code analysis — Bring every scan to a clean result; reach Larastan’s top level.
- **NFR-17** ★ API security: encryption, validation, auth, API gateway — Configure API gateway definitions and partner quotas.
- **NFR-18** ★ Patch and vulnerability management incl. third-party libraries — Build an operating history of patching.
- **NFR-19** ★ Enterprise backup and recovery in-country (RPO / RTO) — Run a real restore from backup.
- **NFR-20** ★ Monitoring with real-time alerts — Switch on the alert rules in Azure.
- **TEC-01** ★ Stable 24/7 — Prove 24/7 operation over time.
- **TEC-02** ★ 10,000 concurrent users; response time < 1.5 s — Run the 10,000-user k6 load test (p95 < 1.5 s). Not run yet; no result is claimed.
- **TEC-03** ★ 20–30 % yearly user growth without performance loss — Re-run the growth model on measured data.
- **TEC-05** ★ Disaster recovery and business continuity with automation — Run a disaster-recovery drill.
- **TEC-16** ★ Automatic patching without user impact — Exercise the blue-green release on Azure.

### Documents to finish with the centre (9)

Drafted in the repository. They need workshops, real dates, screenshots and sign-off.

- **UTR-01** Role-tailored manuals with screenshots, videos and a downloadable PDF — Real annotated screenshots and short videos in the role manuals.
- **NFR-04** ★ HLD / LLD, bill of materials, sizing and bandwidth design — Full Arabic HLD / LLD and a priced bill of materials.
- **DLV-01** As-Is and To-Be process analysis documents — Confirm the As-Is items in workshops with the centre.
- **DLV-02** Needs assessment, scope document, project plan, BRD — Validate the needs assessment with the centre’s data.
- **DLV-03** UX design and system architecture — Add screenshots to the UX and architecture document.
- **DLV-04** Alpha, Beta and Final releases — Tag the Alpha, Beta and Final releases with the centre.
- **DLV-05** User manuals and training & adoption plan — Deliver the training; add screenshots to the manuals.
- **DLV-06** Test plan, test reports, bug tracker — Run UAT, the load test and the VAPT, and attach the results.
- **DLV-07** Go-live plan, handover report, QA certificate, SLA — Fill in real dates, names and evidence at go-live.

## Found and fixed in this check

- **Video anti-distraction rules were saved but never applied (TYP-13 ★).** Authors could switch on “Require full screen” and set “Maximum pauses”, but the player never received either. Now the server credits no time outside full screen and counts the learner’s own pauses in the database, so a reload does not reset the count. The web player pauses when the learner leaves full screen and refuses pauses once the limit is used. The app enforces the pause limit too. Pauses the player makes itself (an in-video question, a hidden page) are not counted.
- **Minimum time per slide was accepted and ignored.** A presentation’s minimum seconds per slide is now enforced: the web viewer reports a slide only after that time, and the server refuses a slide reported too soon. Authors set it in the lesson editor.
- **“Required before the certificate” on evaluation forms did nothing.** A certificate now waits until its holder answers every evaluation form marked as required. The course page lists what is missing, the download error says which form is pending, and the trainee is notified once it is answered. The form designer has the switch.
- **Two parts of the lesson page shared one identity.** The player and the lesson discussion panel used the same React key, which made React warn on every render and can leave the wrong panel on screen when the learner moves between lessons. Found while testing the player in the running app; each now has its own key.
- **The lesson editor showed the wrong default.** “Do not count time when the page is hidden” appeared switched on for every lesson, while the server treated it as off unless it was saved. The editor now shows the real default.

Tests: `LessonAttentionRulesTest` (5 tests, written first and seen failing). The pause limit was also exercised in the running app: two pauses counted, a third refused by button, by clicking the video and by a direct pause, and the count kept after a reload.

## Corrections to the project’s register

- **Exam rows were scrambled.** In the project’s own register, the evidence for 13 exam and assessment requirements had shifted onto the wrong rows: TYP-04/09/11/13, PAS-02 and EXM-01/04/05/06/07/09/10. EXM-09 and EXM-10 had swapped statuses too. Each row was re-checked against the code and corrected.
- **Face recognition had been claimed.** EXM-10 (anti-cheating with face recognition) was marked Available. Integrity tracking, live invigilation and consent-based camera snapshots exist, but nothing detects faces. It is now Partial, and the unused `face_check` setting is documented.
- **Withdrawal and external-form evidence was off by one row.** Corrected for EXT-02/03 and WDR-01..05. The features themselves were confirmed in the code.
- **The 31 mandatory items were never updated.** This table still showed the 5 October picture: 6 items Missing and 14 Partial. It feeds the in-app RFP Compliance screen and the compliance sheet. It is now derived from the requirements behind each item: 21 met, 4 partial, 6 vendor items.
- **Five items waited on phases that are finished.** RBA-03, RBA-06, RBA-10, RBA-11 and TYP-20 still read “arrives in Phase X”. The roles’ permissions were checked against the routes that now exist, and all five are Available.

## The 31 mandatory items

| # | Requirement | 5 Oct | Now | Notes |
|---:|---|---|---|---|
| 1 | Ready, customisable product | Vendor | Vendor | Vendor qualification — answered in the proposal, not by the software. |
| 2 | Support team, developers and PM based in Qatar | Vendor | Vendor | Vendor qualification. |
| 3 | ≥ 10 years’ experience with ministry-scale clients | Vendor | Vendor | Vendor qualification. |
| 4 | ≥ 5 similar projects delivered | Vendor | Vendor | Vendor qualification. |
| 5 | Core business is digital solutions | Vendor | Vendor | Vendor qualification. |
| 6 | Company office in Qatar | Vendor | Vendor | Vendor qualification. |
| 7 | Bilingual, responsive, all browsers, seamless role switching | Partial | Available | UX-05, UX-06, UX-07, UX-08 — all available. |
| 8 | Role-based dashboards with interactive indicators | Partial | Available | HOM-06 — all available. |
| 9 | Pre/post assessment of trainee level (in-person) | Partial | Available | TYP-04 — all available. |
| 10 | Synchronous training via Microsoft Teams | Partial | Available | TYP-05 — all available. |
| 11 | Interactive video, access-code exams, anti-distraction, SCORM / H5P | Missing | Available | TYP-07, TYP-11, TYP-13, TYP-15 — all available. |
| 12 | Blended training | Available | Available | TYP-18 — all available. |
| 13 | Tool to build career-promotion training paths | Missing | Available | CAR-01 — all available. |
| 14 | E-form to register users from outside the Ministry | Missing | Available | EXT-01, EXT-02, EXT-03 — all available. |
| 15 | Individual & institutional needs tools + annual plan | Partial | Available | NDS-01, NDS-02, NDS-03, NDS-04, NDS-07 — all available. |
| 16 | Entities, seats, priority, waiting list, targeting, time-conflict prevention | Partial | Available | ENR-01, ENR-02, ENR-03, ENR-06, ENR-07 — all available. |
| 17 | Self, manager and admin registration | Available | Available | REG-01, REG-03, REG-04, REG-05 — all available. |
| 18 | Direct attendance by trainer + Teams duration-based attendance | Partial | Available | ATT-02, ATT-07 — all available. |
| 19 | Content admin panel: create, edit, share | Available | Available | CNT-02 — all available. |
| 20 | Pass rules, multiple certificate types, QR / number verification | Partial | Available | PAS-01, PAS-10, PAS-14 — all available. |
| 21 | Room occupancy calendar (week / month) | Available | Available | ROM-08 — all available. |
| 22 | Flexible withdrawal rules | Missing | Available | WDR-01, WDR-02, WDR-03, WDR-04, WDR-05 — all available. |
| 23 | Survey types, per-option analytics, Excel / PDF / Word export | Partial | Available | SRV-01, SRV-02, SRV-03, SRV-04, SRV-05, SRV-06, SRV-07, SRV-08 — all available. |
| 24 | Kit archiving with versions linked to courses | Available | Available | KIT-01 — all available. |
| 25 | Professional learning communities | Missing | Partial | Open: PLC-03 (partial); PLC-01, PLC-02, PLC-04, PLC-05 available. |
| 26 | Impact measurement with trainee & manager forms, pre/post comparison | Partial | Available | EVL-01, EVL-02, EVL-06 — all available. |
| 27 | Exam variety, categorised banks, anti-cheating | Partial | Partial | Open: EXM-10 (partial); EXM-01, EXM-03, EXM-04, EXM-05 available. |
| 28 | Integrated notifications + multimedia scheduled announcements | Partial | Partial | Open: NTF-03 (partial); NTF-01, NTF-02, NTF-05, NTF-06, NTF-13, NTF-14 available. |
| 29 | Comprehensive PD records outside the Ministry | Missing | Available | CPD-01, CPD-02, CPD-03, CPD-04, CPD-05, CPD-06 — all available. |
| 30 | Permissions at Ministry / school-group / school level | Partial | Available | RBA-13 — all available. |
| 31 | AI: behaviour analytics, smart feedback, adaptive content, predictive reports, ML assistant | Partial | Partial | Open: AI-03 (partial), AI-05 (partial); AI-01, AI-02, AI-04 available. |

## Coverage by module

| Module | Reqs | 5 Oct (A / P / M) | Now (A / P / M) | Coverage |
|---|---:|---|---|---:|
| UX · User Interface & Experience — واجهة المستخدم | 8 | 5 / 2 / 1 | 7 / 1 / 0 | 75% → 94% |
| HOM · Portal Homepage & Dashboards — الصفحة الرئيسية للبوابة | 6 | 1 / 4 / 1 | 6 / 0 / 0 | 50% → 100% |
| RBA · Roles, Responsibilities & Permissions — الأدوار والمسؤوليات والصلاحيات | 13 | 5 / 4 / 4 | 13 / 0 / 0 | 54% → 100% |
| TYP · Training Types — إدارة أنواع التدريب | 20 | 6 / 5 / 9 | 18 / 2 / 0 | 42% → 95% |
| STR · Training Structure — هيكلية التدريب | 5 | 2 / 1 / 2 | 5 / 0 / 0 | 50% → 100% |
| CAR · Career Paths & Professional Licences — المسارات التدريبية والترقي الوظيفي | 5 | 0 / 1 / 4 | 4 / 1 / 0 | 10% → 90% |
| EXT · External User Registration — نموذج تسجيل مستخدمين من خارج الوزارة | 3 | 0 / 0 / 3 | 3 / 0 / 0 | 0% → 100% |
| NDS · Needs Assessment & Annual Plan — حصر الاحتياجات وبناء الخطة التدريبية السنوية | 15 | 1 / 9 / 5 | 14 / 1 / 0 | 37% → 97% |
| ENR · Course Management & Admission Rules — إدارة الدورات التدريبية وضوابط الالتحاق | 8 | 3 / 1 / 4 | 8 / 0 / 0 | 44% → 100% |
| REG · Registration Mechanisms — آليات التسجيل في البرامج التدريبية | 9 | 6 / 2 / 1 | 9 / 0 / 0 | 78% → 100% |
| ATT · Attendance, Leave & Absence — الحضور والانصراف والاستئذان | 11 | 3 / 4 / 4 | 10 / 1 / 0 | 45% → 95% |
| CNT · Content Management & Digital Library — إدارة المحتوى التدريبي | 5 | 1 / 1 / 3 | 4 / 1 / 0 | 30% → 90% |
| PAS · Passing & Certificates — اجتياز البرامج التدريبية وإصدار الشهادات | 14 | 6 / 6 / 2 | 14 / 0 / 0 | 64% → 100% |
| ROM · Training Rooms & Logistics — إدارة القاعات التدريبية | 9 | 5 / 1 / 3 | 9 / 0 / 0 | 61% → 100% |
| WDR · Withdrawal Paths — مسارات الانسحاب | 5 | 1 / 0 / 4 | 5 / 0 / 0 | 20% → 100% |
| SRV · Surveys & Questionnaires — إدارة استطلاعات الرأي والاستبيانات | 8 | 5 / 1 / 2 | 8 / 0 / 0 | 69% → 100% |
| EXM · Exams & Question Banks — إدارة الاختبارات | 11 | 3 / 5 / 3 | 10 / 1 / 0 | 50% → 95% |
| NTF · Notifications & Announcements — إدارة الإشعارات | 14 | 6 / 7 / 1 | 12 / 2 / 0 | 68% → 93% |
| KIT · Training Kits & Content Sharing — أرشفة الحقائب التدريبية ومشاركة المحتوى | 6 | 3 / 3 / 0 | 6 / 0 / 0 | 75% → 100% |
| PLC · Professional Learning Communities — إدارة مجتمعات التعلم المهنية | 5 | 0 / 0 / 5 | 4 / 1 / 0 | 0% → 90% |
| EVL · Training Evaluation & Impact — تقييم التدريب وقياس الأثر | 8 | 1 / 3 / 4 | 8 / 0 / 0 | 31% → 100% |
| CPD · Comprehensive Professional Development — منظومة التطوير المهني الشامل | 6 | 0 / 0 / 6 | 6 / 0 / 0 | 0% → 100% |
| RPT · Reports & Analytics — التقارير والإحصائيات | 11 | 0 / 8 / 3 | 9 / 2 / 0 | 36% → 91% |
| UTR · User Training, Help & Support — تدريب المستخدمين والدعم الفني | 4 | 0 / 0 / 4 | 2 / 2 / 0 | 0% → 75% |
| EKT · Interactive e-Learning Kits — الحقائب الإلكترونية | 1 | 0 / 1 / 0 | 0 / 1 / 0 | 50% → 50% |
| APP · Phase 2 · Multi-platform App — المرحلة الثانية · توفير تطبيق | 1 | 1 / 0 / 0 | 1 / 0 / 0 | 100% → 100% |
| PAY · Phase 2 · Course Purchasing — المرحلة الثانية · شراء الدورات | 4 | 0 / 0 / 4 | 3 / 1 / 0 | 0% → 88% |
| COL · Phase 2 · Collaboration & Knowledge Sharing — المرحلة الثانية · التعاون والتواصل المعرفي | 6 | 0 / 0 / 6 | 3 / 3 / 0 | 0% → 75% |
| AI · Phase 2 · Artificial Intelligence — المرحلة الثانية · الذكاء الاصطناعي | 5 | 0 / 3 / 2 | 3 / 2 / 0 | 30% → 80% |
| GAM · Phase 2 · Gamification — المرحلة الثانية · التلعيب | 5 | 0 / 0 / 5 | 5 / 0 / 0 | 0% → 100% |
| NFR · Infrastructure, Security & Backup — المتطلبات غير الوظيفية | 21 | 3 / 8 / 10 | 9 / 12 / 0 | 33% → 71% |
| TEC · Technical Requirements & Integrations — المتطلبات التقنية والتكامل | 17 | 4 / 6 / 7 | 8 / 9 / 0 | 41% → 74% |
| DLV · Project Deliverables — مخرجات المشروع والمتسلمات | 9 | 0 / 3 / 6 | 2 / 7 / 0 | 17% → 61% |

## Live-demo readiness (RFP section 3, “عرض النظام”)

- ✅ Programs: in person, live online, self-paced — TYP-01, TYP-05, TYP-06, TYP-18
- ✅ Registration and nominations — REG-01, REG-02, REG-03, REG-04, EXT-01
- ✅ Attendance by QR and Teams — ATT-04, ATT-07
- ✅ Issuing and verifying certificates — PAS-08, PAS-14
- ✅ Content with SCORM and xAPI — TYP-15, CNT-01
- ✅ Scenario: a trainee registers and the manager approves — REG-01, REG-02
- ✅ Scenario: running a program and pulling performance reports — TYP-01, TYP-03, RPT-01, RPT-02
- ✅ Management dashboard and KPIs — HOM-06, RPT-09, TEC-17
- ✅ Editing forms, roles and notification rules live — SRV-05, EXT-03, RBA-12, RBA-13, NTF-10
- 🟡 Technical session: architecture and scale — NFR-04, TEC-02 (open: NFR-04, TEC-02)
- 🟡 Technical session: security, penetration testing, data law — NFR-05, NFR-15 (open: NFR-15)
- 🟡 Technical session: HR, Teams and LMS integration — CAR-05, TEC-12, TEC-08 (open: CAR-05, TEC-12, TEC-08)
- 🟡 Technical session: backup, disaster recovery, RTO / RPO — NFR-19, TEC-05 (open: NFR-19, TEC-05)

## How this was verified

- **278 evidence probes**, one or more per requirement: a route, a file or a code pattern that has to exist. 277 pass. The one that fails is the Lusail font files, which the Ministry supplies.
- **API surface:** 412 endpoints named in the 18 phase prompts were matched against the 1,136 real routes. Every functional endpoint exists, a few under a different but equivalent path (for example `social/spaces` for communities).
- **Screens to API:** all 876 API calls in the web app and all 212 API paths in the mobile app resolve to real routes. Every admin and portal page is reachable from the menus.
- **Settings that do nothing:** every setting the admin screens can save was traced to the code that reads it. That found the 4 defects above, plus `face_check`, which is documented under EXM-10.
- **The register itself:** the evidence of all 278 rows was read against its requirement, which found the misplaced and stale rows above.
- **Tests:** 666 backend tests (665 pass, 1 skipped by design), including the 5 new ones; 36 app tests pass; `pint --test` clean; the web app type-checks, lints with no new findings and builds; `flutter analyze` reports no issues.

## Full matrix

| ID | Requirement | 5 Oct | Now | Evidence |
|---|---|---|---|---|
| UX-01 | Simple, clear UI; understandable icons with short text labels | Available | Available | Luxury government design system, labelled lucide icons, glass cards. |
| UX-02 | Approved visual-identity colours and modern design elements | Available | Available | Qatar Government brand (Al Adaam maroon, Dune) + live Brand Studio. |
| UX-03 | Approved Lusail typeface and readable font sizes | Partial | Partial | Lusail is first in every font stack and registered automatically from `web/public/fonts/lusail/` when the licensed files are added (see `lib/fonts.ts`, `public/fonts/lusail-README.md`); falls back to Qatar Sans → Tajawal. Body line height ≥ 1.65. **The Ministry must supply the font files** (web, mPDF, app). |
| UX-04 | Few steps per task, clear navigation, quick search for content & functions | Partial | Available | Ctrl/⌘ K global search on every page (`components/search/GlobalSearch.tsx`, `GET /search`): programs, people, trainers, kits, certificates, news and functions, permission- and scope-aware, with recent searches; app search screen (`features/search`). |
| UX-05 ★ | Full Arabic/English with instant switch, correct RTL/LTR, professional translation | Available | Available | i18next (web), Flutter l10n, API X-Locale; all content stored as *_ar / *_en. |
| UX-06 ★ | Each user keeps a preferred language, switchable without re-login or data loss | Available | Available | users.locale persisted; switching is instant. |
| UX-07 ★ | Responsive on desktop, tablet and phone, all browsers | Available | Available | Tailwind responsive web + Flutter mobile app. |
| UX-08 ★ | Seamless switching between a user’s roles (trainer / trainee / manager) | Missing | Available | Role switcher in the account menu (web) and on the profile screen (app): `POST /auth/active-role`, `X-Active-Role` header, only the active role's permissions and scope count (`ActiveRole`, `ResolveActiveRole`); remembered per device; `ActiveRoleTest`. |
| HOM-01 | Add / edit / delete news, activities and events | Partial | Available | News, circulars, activities and events managed in one board; events have date, venue, online and registration links, capacity, in-platform RSVP with waiting list, reminders, public events page and ICS calendar (Phase 11). |
| HOM-02 | Signed-in users see news and events | Available | Available | Public news pages and portal notification centre. |
| HOM-03 | Export news / events to the Ministry website (API or file) | Missing | Available | Public JSON, RSS, Atom and CSV feeds (only items flagged for export), manual export files, and an automatic push adapter with retries, log and failure alerts. The push needs the Ministry site's real endpoint and key (Phase 11). |
| HOM-04 | Fully dynamic homepage editable by admin (texts, images, links, ads) | Partial | Available | Block-based homepage and About editor (hero, statistics, programs, news, events, rich text, call to action, logos, FAQ, video, safe HTML) with visibility windows, audience, live desktop/phone preview in both languages, versioned publish and restore; the built-in design stays until the first publish (Phase 11). |
| HOM-05 | Dynamic public statistics (users, courses, centre-defined figures) | Partial | Available | Public statistics from built-in sources (users, programs, groups held, certificates, hours, schools) or values and named indicators the centre defines; cached and refreshed every ten minutes (Phase 11). |
| HOM-06 ★ | Dashboards for every user category, driven by role | Partial | Available | A dashboard for every role from a widget registry (trainee, principal, deputy, head of training, supervisor, leadership, executive, trainer, admin, kit developer, QA, planning, logistics): scope-aware, date range, drill-down to the report, personal hide/reorder, presets editable by administrators; on web and in the app home (Phase 12). |
| RBA-01 | Trainee | Available | Available | Employee role + self-service web portal and mobile app. |
| RBA-02 | School Principal | Available | Available | School Admin role, school-scoped data. |
| RBA-03 | PD Officer (Academic Deputy): approve PD records & nominations, run internal workshops | Missing | Available | Role `academic_deputy` at school / school-group / department scope: approves PD records (`pd.approve` on `/admin/pd-activities`), approves staff registrations as direct manager, nominates, and runs internal workshops (`workshops.internal`). |
| RBA-04 | Head of Training Department | Missing | Available | Role `training_head`: assigns the program supervisor, grants per-program rights (`program_grants.manage`), approves kits (`kits.review`, `kits.publish`); `RolesManagementTest`, `ProgramGrantsTest`. |
| RBA-05 | Training Supervisor | Partial | Available | Role renamed «مشرف التدريب / Training Supervisor»; per-program grants for attendance, notifications, task review and kit assignment (Program → Staff & grants, `ProgramGrantService`). |
| RBA-06 | Centre Leadership & Policy Makers | Partial | Available | Role `center_leadership`: leadership dashboards, plan approval (`plans.approve`), trainer-assignment approval (`trainers.approve` on `/admin/group-trainers/{id}/decision`) and low-satisfaction alerts (default alert recipients). |
| RBA-07 | Trainer | Available | Available | Attendance, materials, task review. |
| RBA-08 | System Administrator | Available | Available | Super Admin / Centre Admin with full permissions. |
| RBA-09 | Kit Developer and Quality Assurance | Available | Available | Dedicated roles with the full kit review workflow. |
| RBA-10 | Head of Planning and Planning Specialist | Missing | Available | Roles `planning_head` and `planning_specialist`: needs cycles and proposals, competency framework and gaps, annual plan (head approves), instrument approval (`instruments.approve`), evaluation forms, interviews and evaluation reports (specialist prepares, head approves). |
| RBA-11 | Logistics Support Officer | Missing | Available | Role `logistics_officer`: room data (`rooms.manage`), non-training room bookings (`rooms.book`) and the logistics-request queue (`logistics.manage`). |
| RBA-12 | Create new roles and permissions when needed | Partial | Available | Settings → Roles & permissions: create a role from scratch or clone one, edit its scopes and landing page, permission matrix with diff preview, delete when unused (`RoleAdminController`, audited). |
| RBA-13 ★ | Permission scope: Ministry / school group / single school | Partial | Available | Roles are granted at Ministry / school group / school / department scope with an optional end date (`role_user` scope columns, `AccessScope`); school groups managed in Settings → School groups (CSV import); every list, dashboard and search is scoped (`AccessScopeTest`). |
| TYP-01 | In-person: registration, acceptance, attendance | Available | Available | Full lifecycle with QR attendance. |
| TYP-02 | In-person: training-room allocation | Available | Available | Room conflict check and best-fit room suggestion. |
| TYP-03 | In-person: pass recording and certificates | Available | Available | Smart Certificate Engine. |
| TYP-04 ★ | Pre- and post-program assessment of the trainee’s level | Partial | Available | Pre- and post-tests built from the question bank (assessment kinds `pre_test` / `post_test`); knowledge gain per trainee, group and skill against the 35 % target (`KnowledgeService`). |
| TYP-05 ★ | Synchronous remote training via Microsoft Teams | Partial | Available | Teams meetings created automatically for online sessions, updated and cancelled with the schedule, join link delivered to trainees, group teams and files. Built on the documented Graph API; not run against a live tenant (Phase 13). |
| TYP-06 | E-learning hierarchy: categories, programs, chapters, topics, recorded video | Available | Available | Category → Program → Module → Lesson (video, slides, quiz, survey, article). |
| TYP-07 ★ | Interactive video: in-video questions / comments, pop-up control, progress gating | Missing | Available | Interactive video interactions with blocking and anti-distraction rules |
| TYP-08 | Chapter quizzes from a random bank, auto-graded, gate the next chapter | Partial | Available | Assessment builder: sections, random draw, difficulty mix, timer, attempts |
| TYP-09 | Final exams with retry rules and re-study after failure | Partial | Available | Final exams with attempt limits, a cooldown between attempts and a re-study rule: after a failed attempt the linked lessons must be studied again before the next try (`restudy_on_fail`, `cooldown_hours`). |
| TYP-10 | Contact the trainer and ask questions from inside the course | Missing | Available | Ask the trainer from any lesson (web and app): routed to the group's trainers with a reply deadline, trainer inbox, answer notifies the learner. Phase 14. |
| TYP-11 ★ | Exams taken remotely or in-centre via a secret access code | Missing | Available | Static and rotating secret access codes for exams taken remotely or in the centre (`POST /admin/assessments/{id}/access-codes`); the code is checked when the attempt starts. |
| TYP-12 | Offline learning: watched content offline, sync on reconnect, resume exams | Missing | Partial | Offline manifest and idempotent sync done; encrypted downloads and offline exams in the app not built |
| TYP-13 ★ | Anti-distraction: prevent pause, seek or minimise during video | Partial | Available | Seek lock, maximum speed, minimum watched share, automatic pause and no credited time while the page is hidden, a full-screen requirement (no time counts outside full screen) and a pause limit counted on the server (`lock_pause`, survives reloads); the app enforces the pause limit — full screen is a web-player rule. |
| TYP-14 | Integrate external platforms (Coursera, edX, Udemy, LinkedIn Learning) via APIs | Missing | Partial | External provider courses become programs with completion sync; needs real provider access |
| TYP-15 ★ | SCORM and H5P support with tracking and reuse | Missing | Available | SCORM 1.2/2004 and H5P packages upload, play, track — docs/rfp/phase-10-content-standards.md (generated-package tests; vendor packages to be tried) |
| TYP-16 | Admin suggests / assigns programs by history, job title or job group | Available | Available | Recommendation engine, audience builder, centre nomination. |
| TYP-17 | Advertise and register for programs on other platforms (e.g., I-earn) | Missing | Available | Programs on other platforms: launch, evidence upload, centre review, hours counted |
| TYP-18 ★ | Blended programs (in-person + synchronous + self-paced) | Available | Available | Per-session mode (in-person / online) plus an attached e-course. |
| TYP-19 | Indirect training (knowledge transfer): indirect beneficiaries, transferred hours, evidence uploads within a deadline | Missing | Available | Knowledge transfer with beneficiaries, hours, evidence, deadline and review |
| TYP-20 | School internal workshops approved by the centre: create, register, attendance, results, certificates | Missing | Available | Internal workshops (`/admin/internal-workshops`): the school submits, the centre approves with a reason (`workshops.approve`), the school registers its own staff and receives attendance and notification rights on the workshop; results and certificates go through the centre's passing and certificate engine, and the hours count as internal PD hours. |
| STR-01 | Category level | Available | Available | Configurable program categories. |
| STR-02 | Main program → optional sub-programs | Missing | Available | Main program → sub-program (one level, enforced) with roll-ups: `POST /admin/programs/{id}/sub-programs`, `GET .../tree`; program page → Structure tab. |
| STR-03 | Training groups (cohorts) under a program with own dates, trainers, seats | Missing | Available | Training groups with own dates, seats, supervisor, room, trainers, sessions and status (`training_groups`, `TrainingGroupService`); group-aware registration, waiting list, attendance and certificates; program page → Groups tab; mobile group picker. |
| STR-04 | Workshop / training-day level | Available | Available | Program sessions act as training days. |
| STR-05 | Assign trainers and kit developers per group / program with an assignment form and leadership approval | Partial | Available | Trainer proposal per group → trainer fills the assignment form (web + app) → leadership approves with the competent authority reference (`TrainerAssignmentService`); kit developers assigned with a due date (`POST /admin/programs/{id}/kit-developers`). |
| CAR-01 ★ | Promotion paths linked to experience, grade and annual appraisal | Missing | Available | Promotion/specialisation paths with levels and conditions — docs/rfp/phase-09-career-pd.md |
| CAR-02 | Professional-licence programs for the four licence levels | Missing | Available | Four-level licence paths with validity and renewal |
| CAR-03 | Conditions per program / licence block progress until met | Partial | Available | Conditions engine in eligibility syntax; blocks level-restricted programs |
| CAR-04 | Path-compliance dashboards with automatic gain/loss notifications | Missing | Available | Compliance funnel/matrix with export; gain and loss notifications to employee and manager |
| CAR-05 | HR integration for experience, grades and appraisals | Missing | Partial | HR / Mawared full and delta sync with conflict policy, leaver deactivation and signed inbound changes. Routing profile change requests to HR is not built; needs the HR interface specification (Phase 13). |
| EXT-01 ★ | Public e-form for non-Ministry users, shareable by link | Missing | Available | Public form `/join/{slug}` for people outside the Ministry: bilingual, step by step, with e-mail verification code (rate limited), conditions (allowed domains) and a shareable link. |
| EXT-02 ★ | Approval workflow: notify admin, review, approve / reject with reason, email result | Missing | Available | Requests are reviewed with all their data: approve (creates the account and, for trainers, the trainer profile with an activation link), reject with a reason, or ask for more information; applicants are told by e-mail at every step and each submission keeps a number and an immutable PDF snapshot. |
| EXT-03 | Configurable form fields, target categories and extra conditions | Missing | Available | Form designer per audience (`/admin/registration-forms`: trainee, trainer, other): fields of 10 types with Arabic/English labels and options, required flags, allowed e-mail domains, opening and closing dates and a shareable link. |
| NDS-01 ★ | Needs-assessment toolset that feeds the annual plan | Partial | Available | Needs workspace (`/admin/needs-hub`): cycle + proposals, manager requests, staff needs, performance data, rules, gap analysis and the competency framework; accepted items and gaps flow into the annual plan draft. |
| NDS-02 | Department program-proposal form in a time window (groups, axes, days/hours, kit, trainers, priority) | Partial | Available | Yearly needs cycle with an opening and closing window (`needs_cycles`); department/school proposals carry every RFP field (groups, axes, target jobs, days, hours, kit availability, trainer nominations, importance, justification); submissions outside the window are refused; reminders and auto-close (`tedc:needs-cycles`). |
| NDS-03 | Manager request form for institutional needs with objectives | Partial | Available | Direct-manager requests for their own staff (`institutional_requests`): need degree, objectives, employees, preferred window; reviewed (accept / merge / reject) and converted to plan items. |
| NDS-04 | Individual needs surveys linked to job competencies + manager approval | Partial | Available | Employees declare needs (`POST /me/needs`) and surveys create needs from low self-ratings; the direct manager approves or rejects in bulk with a note, and a configurable auto-approval after N days is audited. |
| NDS-05 | Rule-based needs: new hires, annual appraisals, classroom observations, specialisation, competencies | Missing | Available | Needs rules (`needs_rules`) run nightly and on demand for new hires, appraisals, classroom observations and specialisation/stage; every need carries an explanation and re-runs never duplicate. Licence and test triggers are wired for Phases 06 and 09. |
| NDS-06 | Automatic gap analysis vs competency framework & licence requirements, prioritised | Partial | Available | Competency framework with level descriptors and required levels per job (stage/subject overrides); current level blended from verified level, manager rating, observations and self rating with editable weights and shown evidence; gaps ranked by gap × people × licence weight with explanations, uncovered gaps listed and sent to the plan. |
| NDS-07 ★ | Generate the annual plan (program, audience, priority) with review and approval | Missing | Available | Annual plan (`training_plans`): generated from approved needs with an explained score per item, reviewed, returned or approved by the right role, baseline snapshot, signed copy reference, Excel/PDF export (`AnnualPlanService`, `/admin/plans`). |
| NDS-08 | Yearly planning rules and program types (ترخيص، تمكين، تمهين، تخصيص، تخيير) | Partial | Partial | Yearly rules per plan (priority weights, quarter per priority, max seats and hours per group, minimum fill, carry-over) drive generation. Program-type scope, mandatory categories and total seat/hour caps are stored but not yet enforced. |
| NDS-09 | Program objectives, axes, units, competencies and summary | Partial | Available | Program axes, objectives and units with hours (`program_units`), edited on the program Structure tab; competencies through the existing skills. |
| NDS-10 | Program & group catalogue with tabs per category and full details | Available | Available | Public catalogue with categories, details and eligibility check. |
| NDS-11 | Publish / cancel programs and edit group details | Partial | Available | Groups are created, edited, cloned, published/unpublished and cancelled with a mandatory reason and notifications to registrants, supervisor and managers; the public catalogue lists only published groups. |
| NDS-12 | Flag emergency (unplanned) programs for reporting | Missing | Available | Groups and plan items can be flagged emergency with a reason; the plan execution view splits planned and emergency work and lists unplanned groups as deviations. |
| NDS-13 | Central status board: planned, ongoing, incomplete, postponed, cancelled, completed | Partial | Available | Status board (`/admin/groups`): planned, registration open, ongoing, incomplete, postponed, cancelled, completed; drag a card to change status with the reason dialog; table view and filters; hourly lifecycle job. |
| NDS-14 | Real-time plan execution tracking and deviation detection | Missing | Available | Plan execution (`GET /admin/plans/{id}/execution`): planned vs created vs executed groups, seats and hours, % execution, % changed after approval, emergency share and a deviation list (late, under-filled, cancelled, postponed, unplanned); daily job `tedc:plan-deviations` notifies planning staff. |
| NDS-15 | Approve needs and evaluation instruments before they are distributed | Missing | Available | Needs surveys cannot be published until the planning head approves them (`instruments.approve`); returned with a note, audited and notified. Evaluation forms join the same flow in Phase 08. |
| ENR-01 ★ | Beneficiary entities per program and seat allocation per entity | Missing | Available | Seats per group split across schools, school groups, departments and job groups with an open pool (`group_seat_allocations`, `SeatAllocationService`); the total never exceeds capacity, full entities fall back to the open pool then the waiting list, and unused seats are released hourly (`tedc:seats-release`). Program page → Admission tab. |
| ENR-02 ★ | Configurable registration-priority rules | Missing | Available | Configurable priority rules (`registration_priority_rules`: plan-targeted, approved need, time without training, appraisal, entity priority, job titles, registration date) rank applicants and promote the waiting list, with an explanation per person and a live preview in Admission rules. |
| ENR-03 ★ | Waiting list with automatic promotion | Available | Available | FIFO waiting list, auto-promotion when a seat frees up. |
| ENR-04 | Block repeated or equivalent courses; configurable equivalents | Partial | Available | Equivalent programs (one- or two-way) and a per-program repeat policy (block / warn / allow); staff can override with a reason. |
| ENR-05 | Prerequisites per course, editable by admin | Available | Available | Eligibility rules completed / not-completed. |
| ENR-06 ★ | Prevent time-conflicting registrations (switchable per course) | Missing | Available | Time clashes are blocked at registration against approved programs and at approval of a second overlapping one; the group setting `allow_overlap_until_approved` controls registering in two overlapping groups before either is approved. |
| ENR-07 ★ | Target criteria: gender, entity, school, job title, job group, experience, nationality, stage, specialisation | Available | Available | Smart Eligibility Engine with per-rule explanations. |
| ENR-08 | Extra criteria: experience in/out Ministry & in current title, licence, grade/subject, 3-year appraisal | Missing | Available | Rule editor and audience builder gained Ministry / outside experience, years in the current title, job grade, subjects, grades taught, appraisal min/avg over 3 years and an equivalent-completed field; the licence criterion is hooked for Phase 09. |
| REG-01 ★ | Employee self-registration within the registration window | Available | Available | Window enforced, eligibility explained. |
| REG-02 | Two-stage approval: direct manager (within entity seats) → training centre | Partial | Available | Self-registration goes to the direct manager (supervisor, else the school's academic deputy) and then the training centre; manager registrations skip the first stage; admin imports are approved; the group `approval_mode` can be center_only or auto; the approval path is shown to the trainee on web and in the app. |
| REG-03 ★ | Registration by direct manager, then centre approval | Available | Available | School nomination → pending → centre approval. |
| REG-04 ★ | Registration by system admin, auto-approved, status editable later | Available | Available | Centre nomination with audited override. |
| REG-05 | Bulk registration by Excel import | Available | Available | Excel / CSV import through the same rules. |
| REG-06 | Acceptance tools: priority and prior-training analysis | Partial | Available | Candidate list per group with priority score and explanation, completed programs in 12 months, hours this year vs the annual minimum, same-category completions and attendance; bulk acceptance stops at the seats. |
| REG-07 | No approval before the registration period closes | Missing | Available | Centre approval of self-registrations is blocked until the registration window closes (`approve_after_window`), with a clear message and an audited override reason. |
| REG-08 | Approval / cancellation notices with program details and pass conditions | Available | Available | Event templates per status. |
| REG-09 | Configurable automatic notification rules (register, cancel, missing tasks, completion) | Available | Available | Template per event with channel toggles. |
| ATT-01 | Paper sign-in sheets, then manual entry by an authorised supervisor | Available | Available | Manual marking with recorded_by. |
| ATT-02 ★ | Direct marking by trainer / supervisor, audited | Available | Available | Session attendance screen. |
| ATT-03 | Electronic signature on a tablet | Missing | Available | Tablet kiosk (`/kiosk/sessions/{id}`): large touch targets, the trainee finds their name and signs on screen to check in or out; signatures are stored privately with the record, the kiosk opening is audited, and manual entry by non-centre staff can be limited to the first N minutes. |
| ATT-04 | QR code per workshop / day with a configurable time window | Available | Available | HMAC-signed QR rotating every 30 s, check-in/out, lateness. |
| ATT-05 | Fingerprint attendance-system integration (trainees and trainers) | Missing | Partial | Fingerprint gateway (`FingerprintGateway`): signed webhook (generic HTTP and the ZKTeco ADMS ATTLOG push format) and CSV import match punches to the person and the session running in the device's room, ignore duplicates and report unmatched ones; device registry, test and log on `/admin/absence`. A vendor-specific pull SDK needs the Ministry's device model and network access. |
| ATT-06 | Trainer attendance and staff scanning of trainee / trainer QR | Missing | Available | Trainers record attendance by the session QR, by a staff scan of their personal QR (`/me/attendance-qr`, rotates daily), or by the supervisor; staff scan trainees the same way (grant `attendance.mark` required); QR check-in/out windows per session or globally; trainer minutes feed the hours report. |
| ATT-07 ★ | Teams attendance % from total participation time | Partial | Available | Attendance computed from time in the Teams call (intervals, leave and re-join, minimum presence, late), feeding the attendance percentage and absence rules; trainer marks are kept (Phase 13). |
| ATT-08 | Absence-threshold alert to supervisor; email to trainee & manager with notes | Partial | Available | After each session the hourly job computes absence per trainee, announces a warning and a breach once each (levels configurable), tells the supervisor and the trainee and, on breach, the direct manager; the supervisor adds a note and resends from the Absence page. |
| ATT-09 | Absence excuses with documents and manager approval workflow | Missing | Available | Trainees send absence excuses with documents (web and app); the direct manager approves or rejects; approved excuses mark the days `excused` and, by policy, either leave them out of the maths or count them as attended. |
| ATT-10 | Leave / permission (استئذان) entry with attachments and notification | Partial | Available | Supervisors record late arrival, early leave or temporary leave with minutes, reason and attachments; the minutes are deducted from attendance, the trainee is notified (policy) and a leave can be removed to restore them. |
| ATT-11 | Attendance records and reports; Excel and PDF export | Partial | Available | Detailed attendance and absence per trainee, per group with leave minutes, printable group sheets and the trainee's own attendance, all exportable to Excel, PDF and Word (Phase 12). |
| CNT-01 | SCORM and xAPI compliance for upload and playback | Missing | Available | SCORM runtime and xAPI LRS with native activity recorded as xAPI |
| CNT-02 ★ | Dedicated content admin panel: create, edit, share with permissions | Available | Available | Online course builder + Training Kit Studio. |
| CNT-03 | Content versioning, periodic updates and long-term archiving | Partial | Available | Lesson versions: publish, keep/move learners, diff, restore, archive (quiz questions not pinned) |
| CNT-04 | Digital library (books, journals, AV, kits) with IP rights, audience rules, search, download | Missing | Available | Digital library with rights, audience rules, Arabic search, reader, shelves, ratings |
| CNT-05 | External libraries: Maktabati and Qatar National Library | Missing | Partial | External library search (deep link / JSON API) and import; real Maktabati/QNL endpoints to be configured |
| PAS-01 ★ | Pass criteria with relative weights (attendance, participation, tasks, tests) | Partial | Available | Weighted/all-required passing policy per group, program or global — docs/rfp/phase-07-passing-rules.md |
| PAS-02 | Test builder: MC, multi-select, dropdown, matrix, image/video, drag-and-drop | Partial | Available | Test builder with 14 question types: single choice, multiple select, true/false, dropdown, matrix, image hotspot, ordering, matching, drag-and-drop categorisation, fill in the blanks, numeric, short answer, essay and H5P, all with media. |
| PAS-03 | Required tasks set per course and submitted electronically | Available | Available | Tasks with file / text submissions and versions. |
| PAS-04 | Trainer approves, rejects or returns tasks with notes | Available | Available | Submission review. |
| PAS-05 | Final approval by course supervisor; auto-approval for self-learning | Partial | Available | Trainer-then-supervisor task approval; automatic for self-assessed tasks |
| PAS-06 | Objective questions auto-graded; essays graded manually | Partial | Available | Manual grading queue, regrade with replacement, release of results |
| PAS-07 | Pass via a comprehensive skills test without attending | Missing | Available | Pass by the comprehensive skills test without attending (test-out) |
| PAS-08 ★ | Certificate designer: logos, background, watermark, text, e-signature | Available | Available | Designer from PDF / image templates, bilingual PDF (mPDF). |
| PAS-09 | Certificate hours: total vs actually attended | Partial | Available | Total or actual attended hours on the certificate |
| PAS-10 ★ | Attendance certificate vs pass certificate (or both) | Missing | Available | Attendance, pass or both certificate types with their own templates |
| PAS-11 | Satisfaction survey required before viewing / printing | Available | Available | Download unlocked after the survey. |
| PAS-12 | Keep graduates’ records after they leave; archive and reprint | Available | Available | Records retained; certificates re-renderable. |
| PAS-13 | Manual exception from the attendance condition, documented | Partial | Available | Documented, audited, revocable exceptions with reason and attachment |
| PAS-14 ★ | Public verification by certificate number or QR | Available | Available | /verify page with QR. |
| ROM-01 | Training places with map / website link | Available | Available | Coordinates and location details. |
| ROM-02 | Room details: name, type, capacity, description, floor/building, equipment | Available | Available | Rich room catalogue. |
| ROM-03 | Allocate rooms to workshops by schedule | Available | Available | Session room assignment. |
| ROM-04 | Book rooms for non-training use by authorised users | Missing | Available | Authorised staff book rooms for meetings, exams, events or maintenance (`/admin/room-bookings`); conflicts are checked against sessions and other bookings and show who holds the room; the occupancy calendar shows sessions and bookings together. |
| ROM-05 | Block double booking and show the occupying program | Available | Available | Conflict error lists the occupying sessions. |
| ROM-06 | Never approve more trainees than room capacity; capacity per room / place / building | Partial | Available | Effective capacity = the lowest of the room, its building and its place; assigning a room to a session and approving registrations both refuse to exceed it (override with a reason); places → buildings → rooms hierarchy. |
| ROM-07 | Seating plan inside the room | Missing | Available | Seating designer per room and session or group: grid with blocked seats, manual assignment, automatic assignment (alphabetical, by school, random) with an 'insufficient seats' check, and a printable plan. |
| ROM-08 ★ | Weekly / monthly occupancy calendar, live free / booked view | Available | Available | Room wall, availability view, door screens. |
| ROM-09 | Logistics requirements routed automatically to the logistics team | Missing | Available | Logistics requests (equipment, catering, printing, IT, arrangement) go to the logistics team's queue, are tracked New → In progress → Done with notifications to the requester, and overdue ones escalate hourly. |
| WDR-01 ★ | After manager approval, withdrawal needs the manager’s approval | Missing | Available | After the direct manager approved a registration, withdrawing becomes a request that the manager decides. |
| WDR-02 ★ | After centre acceptance: manager then supervisor approval + reason form with attachments | Missing | Available | After the centre accepted the seat, the request goes to the manager and then the program supervisor, with a reason form, optional attachments and rejection notes. |
| WDR-03 | Record timing: during window / before start / after start | Missing | Available | The timing of every withdrawal is recorded (during the registration window / before the start / after the start) and late withdrawals are flagged. |
| WDR-04 | Free withdrawal while not yet approved | Available | Available | A trainee withdraws freely before the manager approved while registration is open; the seat is released and the waiting list promoted. |
| WDR-05 ★ | Withdrawal rules configurable without code | Missing | Available | Withdrawal policy and reasons are settings, no code: minimum days before the start, whether withdrawal after the start is allowed, and which reasons require attachments. |
| SRV-01 ★ | Trainee satisfaction survey | Available | Available | Program survey (auto / manual opening) + evaluation. |
| SRV-02 ★ | Training-impact surveys | Available | Available | 30 / 60 / 90-day surveys. |
| SRV-03 | Planning-team program evaluation form | Missing | Available | Planning-team evaluation form assigned by the planning head — docs/rfp/phase-08-evaluation.md |
| SRV-04 | Trainer self-reflection form | Missing | Available | Trainer self-reflection assigned at group end; results notify planning |
| SRV-05 | Edit questions; create surveys for any purpose | Available | Available | Survey Studio with templates. |
| SRV-06 | Question types: choice, rating / stars, open, etc. | Available | Available | Rating, NPS, choice, multiple, text. |
| SRV-07 ★ | Results per option with charts and tables | Available | Available | Survey report with charts. |
| SRV-08 ★ | Export results to Excel, PDF and Word | Partial | Available | Excel / PDF / Word / CSV export for satisfaction, evaluation forms and needs surveys |
| EXM-01 ★ | Final, short and diagnostic tests | Partial | Available | Assessment kinds: quiz, final, diagnostic, pre-test, post-test, comprehensive skills test and practice. |
| EXM-02 ★ | Timed and open-duration tests | Available | Available | Time limit per quiz. |
| EXM-03 ★ | Question types: multiple choice, multi-select, true/false | Available | Available | Supported. |
| EXM-04 ★ | Question types: essay, matching, ordering, fill-in, categorisation, H5P, extensible | Missing | Available | Essay, matching, ordering, fill in the blanks, categorisation, hotspot, numeric, matrix, dropdown, short answer and H5P items; new kinds plug into the `QuestionType` registry. |
| EXM-05 ★ | Question banks by course / unit / difficulty, reusable, with media | Missing | Available | Question banks by course, unit, category and difficulty with tags, versions, media, reuse across assessments and import/export (QTI). |
| EXM-06 | Random selection from a bank | Partial | Available | Random draw from a bank by category and difficulty mix, with shuffling of questions and options. |
| EXM-07 | Auto + manual grading, immediate / deferred feedback, question weights | Partial | Available | Automatic grading of objective items, a manual grading queue for essays and short answers, per-question points, regrade, and immediate or deferred release of results. |
| EXM-08 | Attempts, time limit, show / hide results | Available | Available | Supported. |
| EXM-09 | Access codes and submission timestamps | Partial | Available | Static and rotating access codes; the server stamps start, every autosave and submission (server-owned timer, resume, extra time). |
| EXM-10 ★ | Anti-cheating: activity tracking, face recognition | Missing | Partial | Integrity tracking (tab switch, focus loss, full-screen exit, copy/paste, multiple tabs, devtools) with thresholds that flag or auto-submit, live invigilation (flag, extend, void) and optional camera snapshots with the trainee's consent. **Automatic face recognition is not built** — the `face_check` setting is accepted but nothing detects faces; snapshots are reviewed by people. |
| EXM-11 | Result analytics per trainee, group and program | Partial | Available | Item analysis, difficulty and discrimination, distractors, by group |
| NTF-01 ★ | In-app inbox and pop-up notifications | Available | Available | Notification centre, Supabase realtime, Firebase push. |
| NTF-02 ★ | Email notifications | Available | Available | SMTP channel. |
| NTF-03 ★ | SMS through the Hudhud system | Partial | Partial | Hudhud driver with Arabic UCS-2 encoding, signed delivery-receipt webhook, test button, per-event SMS switches (Phase 11). The request/receipt shape follows a configurable assumption (base URL, path, key or user, receipt secret) and must be matched to the Ministry's Hudhud interface document before go-live. |
| NTF-04 | Templates with branding and dynamic variables | Available | Available | Bilingual templates with variables and preview. |
| NTF-05 ★ | Target by user type, job title, program, school | Partial | Available | Audience builder: roles, job titles, schools, school groups, program participants (by registration status), trainers, supervisors, named people; live count and sample; senders only reach their own scope (Phase 11). |
| NTF-06 | Scheduled notifications and allowed send times / days | Missing | Available | One-off and daily/weekly/monthly scheduled sends processed every minute; per-rule and per-channel allowed days and hours (Doha time) defer SMS, e-mail and push to the next window; delay minutes (Phase 11). |
| NTF-07 ★ | Automatic event-driven notifications | Available | Available | Event catalogue + scheduler jobs. |
| NTF-08 | Manual notifications by admins | Available | Available | Send dialog with audience picker. |
| NTF-09 | Sound or visual alert on a new notification | Partial | Partial | Web: pop-up with a soft chime (per-user switch, browser autoplay rules respected) and a pulsing bell; mobile: system push sound and an in-app preference. No custom local-notification sound on mobile yet (Phase 11). |
| NTF-10 | Enable / disable types per user category; rules per program | Partial | Available | Notification rules per event, program category, program and audience (most specific wins), channel limits, switch-off; users choose optional channels per event group; mandatory events ignore opt-out (Phase 11). |
| NTF-11 | Read / unread centre and sent log (recipient, date, type) | Available | Available | Campaign tracking. |
| NTF-12 | Delivery status sent / read / failed; export PDF / Excel | Partial | Available | Delivery tracking per person and channel (queued, sent, delivered, read, failed, skipped, with reasons), filters, drill-down by campaign, donut summary, Excel and PDF export in Arabic and English (Phase 11). |
| NTF-13 | Announcements: start/end window, several at once, pin, archive, republish, search | Partial | Available | Announcement lifecycle draft, scheduled, published, expired, archived with display window, several live at once, ordered pinning, searchable archive, republish with media copied and window reset, push/e-mail on publish (Phase 11). |
| NTF-14 ★ | Multimedia announcements (link, video, audio, text, image) | Partial | Available | Announcements carry images, video, audio (player on web and mobile), files and links; audience filters; optional push and e-mail (Phase 11). |
| KIT-01 ★ | Archive kits with all versions, linked to related courses | Available | Available | Versions, restore, archive. |
| KIT-02 | Kit-developer account uploads Word, PDF, PowerPoint, video, images, audio | Available | Available | Training Kit Studio. |
| KIT-03 | Supervisor approval, then assignment to one or more programs | Partial | Available | Kits assigned to several programs with pinned version |
| KIT-04 | Upload and view many resource types incl. web links | Available | Available | PDF, DOCX, PPTX, media viewers. |
| KIT-05 | Share resources per course and with job groups (principals, teachers…) | Partial | Available | Job groups as sharing targets |
| KIT-06 | Sharing-permission settings that protect IP | Partial | Available | Per-role sharing policies, view-only protection, audited shares |
| PLC-01 ★ | Create flexible communities by subject, interest or team | Missing | Available | Communities by subject, interest or team, with visibility and join policy, behind the `plc` flag. Phase 14. |
| PLC-02 ★ | Member roles (manager, moderator, member) with permissions | Missing | Available | Owner / manager / moderator / member roles, approval, ban, staff override permissions. Phase 14. |
| PLC-03 ★ | Votes, polls, open questions, comments, file sharing | Missing | Partial | Polls, questions, comments and reactions are built; file sharing is by attachment reference (name + link) — no upload widget in the composer yet. Phase 14. |
| PLC-04 ★ | Meetings and events scheduling with automatic notifications | Missing | Available | Space events with RSVP, notification on creation and a reminder 24 h before. Phase 14. |
| PLC-05 ★ | Instant alerts on new topics and updates | Missing | Available | Instant notification on new posts, comments, mentions, join requests, plus a daily digest; each person chooses all / mentions / none. Phase 14. |
| EVL-01 ★ | Trainee impact form after ≥ 1.5 months, with evidence uploads | Partial | Available | Trainee impact form at 45 days (configurable) with evidence |
| EVL-02 ★ | Manager impact form with evidence | Partial | Available | Manager impact form requested at 60 days (configurable) with evidence |
| EVL-03 ★ | Trainee satisfaction | Available | Available | Program survey. |
| EVL-04 | Program-supervisor feedback | Missing | Available | Program supervisor feedback form |
| EVL-05 | Planning-specialist feedback | Missing | Available | Planning specialist feedback form |
| EVL-06 ★ | Pre / post comparative analysis (knowledge gain) | Missing | Available | Pre/post comparison, knowledge gain vs 35% target, distribution, per-skill, significance hint |
| EVL-07 | Personal interviews log | Missing | Available | Interviews log with sentiment and attachments |
| EVL-08 | Evaluation report: KPIs + classification (successful / needs review / weak) + recommendations | Partial | Available | Program evaluation report with classification, recommendations, approval and export |
| CPD-01 ★ | Log external PD activities with details and evidence | Missing | Available | External PD records with evidence |
| CPD-02 ★ | Hours calculated by activity type and participation level | Missing | Available | Hour rules per type and participation level with caps |
| CPD-03 ★ | Direct-manager approval of activities | Missing | Available | Direct-manager approval (approve / reject / return) |
| CPD-04 | Reports: total hours, distribution by domain / competency, approval rates | Missing | Available | PD reports: hours, domain, approval rates, shortfalls; Excel/PDF/Word |
| CPD-05 | Annual minimum-hours tracking per employee | Missing | Available | Annual minimum hours with alerts, calendar or fiscal year |
| CPD-06 | Request recognition of external courses (centre sets hours / equivalent programs) | Missing | Available | Recognition requests with recognised hours and equivalent programs |
| RPT-01 | Role-specific reports + self-service dynamic report builder | Missing | Available | Report builder for non-technical people: whitelisted datasets, columns with aggregates, plain-word filters, grouping, sorting, chart, live preview, save and share, schedule; nothing typed by a user reaches SQL (Phase 12). |
| RPT-02 | Export reports to Excel, PDF and Word | Partial | Available | Excel (RTL, totals, one sheet per table), PDF (shaped Arabic, charts) and Word from one document model; large runs are produced by the minute job; downloads of personal data are audited; signed expiring links for scheduled sends (Phase 12). |
| RPT-03 | System-admin reports (employees, courses, paths, lookup, attendance, licence matrix, trainers, results, hours, periodic stats) | Partial | Partial | Employee data, employee courses, programs-groups, courses by employee number, detailed attendance, programs/licences matrix, trainer follow-up, multi-table achievement statistics, process tracking, trainee results, programs by job category, satisfaction, approved vs actual hours, periodic statistics (Phase 12). Not covered: the 'path' column is the program category, appraisal and licence filters on the employee report, and a separate quarterly view (use month grouping). |
| RPT-04 | Supervisor reports (printable sheets, workshop calendar, supervised programs, attendance & leave) | Partial | Available | Printable attendance sheets per group, weekly/monthly workshop calendar, groups supervised in a period, attendance/absence/leave per group (Phase 12). |
| RPT-05 | Trainer reports (workshop calendar, delivered programs, process tracking) | Partial | Available | Trainer's workshop calendar, statistics of programs and groups delivered, process tracking of their groups (Phase 12). |
| RPT-06 | Direct-manager reports (team courses, nominations & approval flow, attendance) | Partial | Available | Courses obtained by staff in a period, nominations and approval flow, staff attendance and absence — limited to the manager's own staff (Phase 12). |
| RPT-07 | Trainee reports (calendar, annual / fiscal hours dashboard, eligible programs, history) | Partial | Available | Approved workshop calendar, courses and hours by year or academic year, programs I can apply for, completed courses, my attendance, statement of courses in a period (PDF) — always about the person asking; also in the app (Phase 12). |
| RPT-08 | QA and kit-developer reports | Partial | Available | Approved kits with their programs and supervisors (QA) and a kit developer's own kits and approval status (Phase 12). |
| RPT-09 | Leadership dashboard: plan execution %, plan changes %, high / low satisfaction groups | Partial | Available | Leadership dashboard: plan execution %, changes after approval %, achievement by school and job category, highest and lowest satisfaction, low-satisfaction alerts, drill-down to reports (Phase 12). |
| RPT-10 | Low-satisfaction alert (< 50 % once ≥ 80 % responded, editable thresholds) | Missing | Available | Low-satisfaction alert (response ≥80% and average <50%, editable), once per group |
| RPT-11 | Predictive analytics and reports | Missing | Partial | Forecast and risk pages with explanations and plan hand-off; Excel/PDF export of forecasts is not built. Phase 15. |
| UTR-01 | Role-tailored manuals with screenshots, videos and a downloadable PDF | Missing | Partial | Help centre with 28 bilingual articles for 17 roles, contextual «?» panel by page, versioned editor with screenshot/video upload, role manuals as branded PDF (Arabic or English) built from current versions, guided tours, mobile help (`pages/HelpCentre.tsx`, `components/help/*`, `HelpService`, `HelpCentreTest`). **Not done:** real annotated screenshots and screen-recording videos have not been captured; someone must produce them with the centre (see `docs/rfp/phase-18-adoption-help-deliverables.md`). |
| UTR-02 | Staff training and Train-the-Trainer plan | Missing | Available | Staff training and Train-the-Trainer plan (`docs/deliverables/05-training-adoption-plan.md`): audiences, schedule, materials, assessments, adoption KPIs, change and communication plan, risks. The sessions themselves are delivered as a service with the centre. |
| UTR-03 | Support channels (phone, email, Saaed) | Missing | Partial | Help centre lists phone, email and Saaed portal with working hours and the P1–P4 service levels; «Report a problem» creates a Saaed ticket and shows its status. **The centre must supply the phone, email and Saaed link** (`TEDC_SUPPORT_PHONE`, `TEDC_SUPPORT_EMAIL`, `TEDC_SUPPORT_SAAED_URL`); until then the page says «Set by the centre». |
| UTR-04 | In-portal issue-reporting page linked to Saaed | Missing | Available | Report-a-problem on web and in the app: category, priority, description, screenshot, page and context captured; tickets reach Saaed (queued and retried), status and number come back as notifications. Saaed's API shape is assumed (Phase 13). |
| EKT-01 | Two interactive e-learning kits produced with the centre for phase 1 | Partial | Partial | Two interactive kits in `backend/resources/ekits/kits.json` (portal use; effective classroom questioning) with trainer/trainee guides and session plan; `tedc:ekits-build` publishes them as e-courses (chapter reading + knowledge checks, final assessment drawn from a question bank, certificate) and exports SCORM 2004 packages in Arabic and English (`EKitsTest`: published and completed end to end as a trainee; manifest parsed). **Not done:** the topics are proposals to agree with the centre; content is a compact first version; no H5P interactions, interactive video, drag-and-drop, captions or xAPI; the package was not run in a real LMS. |
| APP-01 | Fast multi-platform app, fully responsive on phones, tablets, computers | Available | Available | Flutter app (Android APK via CI, iOS-ready) + responsive web. |
| PAY-01 | Browse catalogue, add courses to a cart, check out | Missing | Available | Catalogue prices, cart with seat holds, discount codes, VAT, checkout, invoices, receipts, orders and refund requests (web and app). Phase 16. |
| PAY-02 | Pay through the Ministry e-payment gateway | Missing | Partial | Hosted-page redirect with signed server-to-server callback (idempotent), refunds, reconciliation, training gateway; the Ministry gateway's real specification is assumed and has been tested against a mock only. Phase 16. |
| PAY-03 | Entities buy course bundles for their staff | Missing | Available | Entity accounts buy seats, receive vouchers, assign them by employee number or e-mail, staff redeem them (eligibility checked); expiry, reminders, usage dashboard. Phase 16. |
| PAY-04 | Paid / free pricing per trainee category | Missing | Available | Price lists per group or programme with ordered rules per trainee category (free for ministry staff, paid for others, per-seat for entities), preview as each category. Phase 16. |
| COL-01 | Discussion forums per program and per group | Missing | Available | Forums per programme and per group, membership following registrations; trainers moderate. Phase 14. |
| COL-02 | Comments and notes on lessons and materials | Missing | Partial | Private notes and a discussion thread on every lesson; not on individual materials. Phase 14. |
| COL-03 | Content rating and reviews by trainees and trainers | Missing | Partial | 1–5 stars and reviews with moderation for lessons, materials, kits, library items and programmes (API); the interface is on lessons only. Phase 14. |
| COL-04 | File sharing inside discussions | Missing | Partial | Attachments can be referenced in posts and comments; no upload widget yet. Phase 14. |
| COL-05 | Private trainers’ knowledge channel | Missing | Available | Private trainers' channel for active trainers, moderated by the training team. Phase 14. |
| COL-06 | Instant notifications on posts; permissions per role and level | Missing | Available | Notifications on posts, per-person preferences, permissions per role (`communities.*`, `forums.moderate`). Phase 14. |
| AI-01 ★ | Behavioural analytics and personalised recommendations | Partial | Available | Hybrid recommender: rule score + colleagues' completions (item similarity) + own behaviour + same-job ratings + date clashes; explained; weights, A/B test and feedback loop; falls back to the rule engine. Phase 15. |
| AI-02 ★ | Smart assessment with instant feedback from answer analysis | Missing | Available | Instant objective feedback (distractor analysis, competency, what to review) and essay drafts for the grader (rubric criteria, suggested score); a draft never becomes a grade by itself. Phase 15. |
| AI-03 ★ | Adaptive content that adjusts to each learner | Missing | Partial | Mastery per competency, skip-module (test-out, audited) and remedial-lesson rules, personal path with reasons, AI-drafted remedial lessons reviewed by the trainer; difficulty of practice draws is not adapted. Phase 15. |
| AI-04 ★ | Predictive reports on future PD needs | Partial | Available | Next-year forecasts by competency, job title and school (Holt smoothing with range and explanation) and risk lists (hours, licences, under-filled groups, satisfaction); suggested items added to a draft plan. Phase 15. |
| AI-05 ★ | ML assistant that answers trainees’ questions | Partial | Partial | Assistant answering from permitted lessons, programmes and library with citations plus the person's own records, declines off-topic, hands over to trainer or support, in-country by default; retrieval uses local hashed embeddings (no pgvector) and generating answers needs a connected model (extractive answers otherwise); not run against a real Azure endpoint. Phase 15. |
| GAM-01 | Points system and leaderboard | Missing | Available | Points ledger with rules, caps and reversals; weekly / monthly / term leaderboards by ministry, school, region, programme; opt-out. Phase 14. |
| GAM-02 | Badges and achievements | Missing | Available | Rule-based and manual badges with safe SVG icons. Phase 14. |
| GAM-03 | Levels (beginner → expert) | Missing | Available | Six editable levels from newcomer to pioneer; level-up notification. Phase 14. |
| GAM-04 | Timed challenges and rewards | Missing | Available | Timed challenges with audience, goal and reward; rewards shop with level, stock and refunds. Phase 14. |
| GAM-05 | Personal progress dashboard; admin-configurable; can be switched on / off | Missing | Available | Personal achievements page; studio for admins; master flag plus per-role and per-programme switches. Phase 14. |
| NFR-01 ★ | Secure, scalable, resilient multi-layer HA design (99.9 % SLA) | Partial | Partial | HA design and zone-redundant IaC (gateway, Container Apps, PostgreSQL HA, Redis), probes and blue-green release script; nothing deployed or failover-tested yet. Phase 17. |
| NFR-02 ★ | Hosting on Azure Qatar (data residency, Law 13/2016) | Missing | Partial | Bicep for Azure Qatar Central with a location policy, Azure Blob driver (Azurite-tested), health probes, Azure OpenAI support; not deployed — needs the Ministry's subscription. Phase 17. |
| NFR-03 ★ | Production, staging (prod-identical) and development environments | Partial | Partial | dev / staging / prod parameter files with identical topology and separate vaults and databases; not provisioned. Phase 17. |
| NFR-04 ★ | HLD / LLD, bill of materials, sizing and bandwidth design | Missing | Partial | HLD, LLD, BOM and sizing, environment matrix written (English with Arabic summaries); full Arabic translation and priced BOM outstanding. Phase 17. |
| NFR-05 ★ | Encryption at rest and in transit; joint data classification | Partial | Available | Data classification of every column with generated register and a drift test; application-level encryption for national IDs and secrets; TLS/HSTS; Key Vault in IaC. Phase 17. |
| NFR-06 ★ | Role-based access control | Available | Available | 10 roles, 43 permissions, Supabase RLS. |
| NFR-07 ★ | Single sign-on and IAM integration | Missing | Partial | OpenID Connect single sign-on with Microsoft Entra ID (PKCE, strict token validation, just-in-time accounts, group-to-role mapping with scopes, single logout, break-glass), tested against a mock IdP. SAML 2.0 is not built natively and mobile SSO is not wired; needs the Ministry tenant and app registration (Phase 13). |
| NFR-08 ★ | Auth schemes: AD, LDAP, Kerberos, certificates, tokens, OTP | Missing | Partial | LDAP / Active Directory bind-and-search (TLS required) and TOTP, e-mail and SMS one-time codes with recovery codes. Kerberos, certificates and smart-card/FIDO2 are provided by the identity provider (documented, not coded); LDAP needs PHP's ldap extension on the host (Phase 13). |
| NFR-09 ★ | Configurable password policy and account lockout | Missing | Available | Configurable password policy (length, classes, history, expiry, breach check) and lockout with administrator unlock, audited, applied to activation and password change (Phase 13). |
| NFR-10 ★ | User-specific administration accounts | Available | Available | Per-user admin accounts with roles. |
| NFR-11 ★ | Automatic session termination after inactivity | Partial | Available | Server-side sessions with idle and absolute lifetime; expired, ended or terminated sessions are refused immediately and cannot be refreshed; administrators list and terminate sessions (Phase 13). |
| NFR-12 ★ | Secure audit logs with permission-based access; login trail | Available | Available | Append-only audit log + presence sessions. |
| NFR-13 ★ | Forward logs to SIEM (e.g., Splunk) | Partial | Available | Audit and security events queued and forwarded to Splunk HEC or Microsoft Sentinel, JSON logs with request ids; the Ministry's SIEM endpoint is needed to go live. Phase 17. |
| NFR-14 ★ | No production data in dev / test / training; masking | Missing | Available | tedc:anonymise-export with approver, reason and audit; synthetic seed for lower environments; policy and docs forbid copying production data. Phase 17. |
| NFR-15 ★ | VAPT, accredited code review, threat model, risk assessment, security docs | Missing | Partial | VAPT readiness checklist, scope document, remediation tracker, threat model and clearance pack contents; the accredited test itself has not been done. Phase 17. |
| NFR-16 ★ | Secure SDLC: secure coding, threat modelling, code analysis | Partial | Partial | SSDLC document and pipeline (gitleaks, audits, OSV, Semgrep, Trivy, ZAP baseline template, Dependabot); scans not yet run to a clean result and Larastan max level not reached. Phase 17. |
| NFR-17 ★ | API security: encryption, validation, auth, API gateway | Partial | Partial | Throttles, WAF policy with a login rate rule and partner-API gateway (APIM) in the IaC; API definitions and quotas for partners not configured. Phase 17. |
| NFR-18 ★ | Patch and vulnerability management incl. third-party libraries | Missing | Partial | Patching SLAs, weekly image rebuild and Dependabot defined; no operating history yet. Phase 17. |
| NFR-19 ★ | Enterprise backup and recovery in-country (RPO / RTO) | Missing | Partial | PITR 35 d, 7-year monthly vault, blob soft delete/versioning in IaC, DR runbook and monthly restore-test workflow; no restore has been executed. Phase 17. |
| NFR-20 ★ | Monitoring with real-time alerts | Partial | Partial | Probes, action group and alerts (availability, 5xx, p95, queue lag, DB CPU/storage) in IaC; not deployed. Phase 17. |
| NFR-21 ★ | Third-party risk management | Missing | Available | Third-party register with purpose, data, residency, contract status, risk, mitigation and kill-switch for each service. Phase 17. |
| TEC-01 ★ | Stable 24/7 | Partial | Partial | Designed for 24/7 (zones, probes, autoscale, rollbacks); not proven in operation. Phase 17. |
| TEC-02 ★ | 10,000 concurrent users; response time < 1.5 s | Partial | Partial | k6 scenarios to 10,000 users with p95 < 1.5 s thresholds and a sizing model; the test has NOT been run, so no result is claimed. Phase 17. |
| TEC-03 ★ | 20–30 % yearly user growth without performance loss | Partial | Partial | Capacity and cost-growth model for +20–30 % a year with autoscale limits; to be re-run on measured data. Phase 17. |
| TEC-04 ★ | Central browser-based architecture for internal and external users | Available | Available | SPA + REST API. |
| TEC-05 ★ | Disaster recovery and business continuity with automation | Missing | Partial | DR/BCP runbooks, monthly restore-test and release rollback automation templates; no drill performed. Phase 17. |
| TEC-06 ★ | Real-time message-based sync between systems | Partial | Available | Outbox of domain events delivered as signed webhooks with retries, dead-letter and replay; signed idempotent inbound messages; subscriptions managed in the hub. A broker adapter (Azure Service Bus) follows in Phase 17 (Phase 13). |
| TEC-07 ★ | Ministry integrations: Licences, NSIS, QNEDS, HR / Mawared, AD, Saaed, Sijil, Ministry website | Missing | Partial | Integration hub with monitored adapters (health, logs, retries, circuit breaker) for HR, Mawared, licences, NSIS, QNEDS, Saaed, Sijil and the Ministry site. The real system specifications are needed; none was run against the real systems (Phase 13). |
| TEC-08 ★ | LTI 1.1 and LTI 1.3 with Deep Linking | Missing | Partial | LTI 1.1/1.3 platform with Deep Linking, AGS, NRPS done; TEDC as an LTI tool not built |
| TEC-09 ★ | xAPI, IMS Caliper, SCORM, QTI 1.1 / 2 / 2.1, cmi5 | Missing | Available | xAPI LRS, Caliper 1.2, SCORM, QTI 2.1/1.2, cmi5 implemented (ADL conformance suite still to run) |
| TEC-10 ★ | HTML5 content | Available | Available | HTML5 video, slides and articles. |
| TEC-11 ★ | Common Cartridge import (full or selected parts) | Missing | Available | Common Cartridge 1.1-1.3 / thin CC preview and selective import |
| TEC-12 ★ | Advanced Teams: Office 365 forms, structure sync, file sharing, live streaming | Missing | Partial | Meetings, attendance by duration, Team per group with membership sync, channel files and Forms results import (CSV export). Live events are link-only and Teams activity-feed notifications are not wired (Phase 13). |
| TEC-13 ★ | Trusted content-provider integration | Missing | Partial | Provider adapter framework (generic REST + demo driver); real Coursera/edX/Udemy/LinkedIn APIs need credentials |
| TEC-14 ★ | Compatible with phones and tablets | Available | Available | Responsive web + Flutter app. |
| TEC-15 ★ | Cost-effective licensing (perpetual preferred) | Available | Available | Custom-built, owned source code; no per-user licence. |
| TEC-16 ★ | Automatic patching without user impact | Partial | Partial | Revision-based blue-green release with automatic rollback and weekly image rebuilds; not exercised on Azure. Phase 17. |
| TEC-17 | Live KPI dashboard: response time, concurrency, uptime, completion, active users, satisfaction, knowledge gain, security | Partial | Available | Live KPI dashboard of the twelve RFP indicators against editable targets with 30-day trends, breach alerts to administrators, data-integrity detail and a monthly PDF/Word report. Concurrency shows current and peak users, not a proven capacity; uptime comes from an in-app probe (Phase 12). |
| DLV-01 | As-Is and To-Be process analysis documents | Missing | Partial | As-Is / To-Be document drafted in Arabic with an English copy (`docs/deliverables/01-as-is-to-be.md`): every automated workflow described. **As-Is items are marked ⚑ and need confirming in workshops with the centre.** |
| DLV-02 | Needs assessment, scope document, project plan, BRD | Missing | Partial | Needs assessment, scope and project plan drafted (`02-needs-scope-plan.md`); BRD generated from the register by `php artisan tedc:deliverables` (`brd.generated.md`: a section per module, acceptance criteria, open items). The needs assessment must be validated with the centre's data. |
| DLV-03 | UX design and system architecture | Partial | Partial | UX design and architecture document (`03-ux-and-architecture.md`) linking the Phase 17 HLD/LLD and security docs. It contains no screenshots yet. |
| DLV-04 | Alpha, Beta and Final releases | Partial | Partial | Alpha / Beta / Final gates, exit criteria and sign-off defined (`04-release-management.md`), `CHANGELOG.md`. **No release has been tagged**: tags are cut at each milestone with the centre. |
| DLV-05 | User manuals and training & adoption plan | Missing | Partial | User manuals = help-centre PDFs per role (always current); training and adoption plan with Train-the-Trainer (`05-training-adoption-plan.md`). Training has not been delivered; manuals have no real screenshots yet. |
| DLV-06 | Test plan, test reports, bug tracker | Partial | Partial | Test plan (`06-test-plan.md`), generated test report and traceability matrix (`test-report.generated.md`, `traceability.generated.md`), UAT script template, bug-tracker conventions aligned to the SLA matrix. **UAT has not been executed, no load test has been run, no independent VAPT yet.** |
| DLV-07 | Go-live plan, handover report, QA certificate, SLA | Missing | Partial | Go-live plan, handover report outline, QA certificate template and the SLA (P1–P4 response and resolution, penalty formula, escalation) drafted (`07-go-live-handover-sla.md`); they are completed with real dates, names and evidence at go-live. |
| DLV-08 | Data-migration strategy and tooling (validation, cleansing, transformation, secure transfer) | Missing | Available | Data-migration toolkit for employees, trainers, programs, registrations, attendance, certificates and PD: templates, mapping and value conversion, Arabic-aware cleansing, validation report, dry run, chunked import, reconciliation and rollback, audited and secured; strategy in docs/rfp/data-migration.md (Phase 13). |
| DLV-09 | Compliance sheet and RFP traceability matrix | Missing | Available | Settings → RFP Compliance (`pages/admin/RfpCompliance.tsx`, `GET /admin/rfp-status`), `docs/rfp/compliance-sheet.md`, `php artisan tedc:rfp-status`, `RfpStatusTest`. |
