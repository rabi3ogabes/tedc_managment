# Survey Studio (ستوديو الاستبانات) — Gate 0: discovery report and Gate 1 proposal

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` (commit 68d5f39) · Status: **read-only inspection, nothing implemented**

This report answers the master prompt's Gate 0: the actual framework versions, the architecture, the current state of surveys and evaluations in TEDC, the tests that exist, the database setup, the authentication approach, the security concerns and the missing configuration. It then proposes the Gate 1 decisions and lists the decisions only the Ministry can take. Every statement below was read in the repository; nothing is assumed from the prompt.

---

## 1. Actual framework versions (from the lock files)

| Layer | What is actually installed | Prompt's preference | Verdict |
|---|---|---|---|
| Backend | Laravel **13.34.0** (`composer.lock`), PHP `^8.3` (8.4 locally and in CI) | Laravel latest LTS | ✓ matches |
| Web front end | React **19.2.8**, TypeScript **6.0.2**, Vite **8.3**, React Router **7.18**, TanStack Query **5.104**, Tailwind **v4**, Recharts **3.10** | Inertia.js + React or Vue | ✗ differs: TEDC is a **separate SPA talking to a REST API** (`/api/v1`, 1,191 routes) |
| Mobile | Flutter app (needs Dart ≥ 3.13.4) sharing the same API | not in prompt | extra client that must keep working |
| Database | **SQLite** locally and in the default CI job; **Supabase PostgreSQL** (Singapore region) in production; a PostgreSQL CI job exists; pgvector in use | PostgreSQL | ✓ production matches; code must stay portable to both |
| Queue / cache / session | `database` drivers locally; on Vercel the queue is forced to **`sync`** (`backend/scripts/vercel-env.php`) | queue workers | ⚠ no background workers in production hosting |
| Scheduler | `tedc:open-surveys` every 15 min, `tedc:evaluations-hourly`, `tedc:dispatch-surveys` daily 08:00 in `routes/console.php`; on Vercel a single cron hits `/api/v1/system/cron` **once a day at 05:00** (`vercel.json`) | — | ⚠ 15-minute and hourly jobs effectively run once a day on the live site |
| Exports | `mpdf/mpdf ^8.3` (Arabic PDF, already used), `phpoffice/phpspreadsheet ^5.10`, Word via `Reports/SurveyDocument` | Arabic PDF/Excel/CSV | ✓ available |
| QR codes | `chillerlan/php-qrcode ^6` (used by attendance and certificates); `qrcode.react` on the web (session QR, wallet, certificate canvas) | QR per survey | ✓ library present, **not wired to surveys** |
| AI | `AiGateway` with drivers openrouter / openai / anthropic / custom / azure and tasks text / image / audio / video / embeddings, configured in Settings → AI models | optional AI | ✓ gateway exists; no survey use yet |

**Gate 1 consequence:** the prompt's "Inertia.js" preference should be set aside. Introducing Inertia would mean a second rendering model inside a 140-route SPA, would not serve the Flutter app, and brings nothing the REST + React stack lacks. Everything else in the prompt's preferred architecture (Laravel, PostgreSQL, queues, Tailwind) is either already true or achievable.

## 2. Architecture as it stands

- **Monorepo**: `backend/` (Laravel API), `web/` (React SPA), `mobile/` (Flutter), `docs/`, `infra/`.
- **API**: versioned REST under `/api/v1`, route groups guarded by `permission:<key>` middleware, throttles per concern (`throttle:public`, `throttle:ai`, `throttle:external-submit` …). Errors are JSON with translation keys; the web app has a shared `ErrorState` (403 variant added this week).
- **Authorization**: RBAC with role → permission keys (`RolePermissionSeeder`), data **scopes** (school / region / department) and role switching. Survey-adjacent keys that exist today: `needs.view`, `needs.submit`, `needs.manage`, `needs.cycles`, `needs.propose`, `instruments.approve`, `evaluations.manage`, `evaluations.respond_planning`, `evaluation_reports.prepare`, `evaluation_reports.approve`. **There is no `surveys.*` permission family.**
- **Feature flags**: `config/features.php` + Settings → Features; production-safety guards (`DemoGuard`, `TEDC_DEMO_SITE`).
- **Cross-cutting services** that a survey platform must reuse: `AuditLog` (used by auth, settings, approvals), SIEM outbox, `NotificationService` with editable templates and channels (e-mail / SMS / push / Teams), `FileStorage` (Supabase / Azure / local, content-type checks), `DataClassification` register + `Anonymiser` export, `ChannelSettings` (mail readiness), `EmailSender` (bilingual), and the public **external registration forms** (`Public/ExternalFormController`: slug, e-mail verification code, submit, dedicated throttles), which is the closest existing pattern to a public survey link.

## 3. Current state of surveys and evaluations (what to extend, not replace)

TEDC already has **three** survey-like engines. They do not share a schema or a renderer on the back end, and only partly on the front end.

### 3.1 Needs surveys (`needs_surveys`) — the current "ستوديو الاستبانات"
- Tables: `needs_surveys` (title, description, status, source, template_key, **questions json**, **audience json**, **settings json**, created_by, published_at, closes_at, closed_at), `needs_survey_recipients` (notified / reminded / responded), `needs_survey_responses` (user_id + a **snapshot of employee attributes**: school, job title, experience, specialization, nationality, gender, stage; answers json; duration; submitted_at).
- Schema service `NeedsSurveys/SurveySchema`: **14 question types** `section, short_text, long_text, single, multiple, dropdown, yes_no, rating, scale, nps, matrix, ranking, number, date`; `required`, `max_select`, scales, matrix rows with competence/need mode and a link to a skill; **conditional display** `show_if {question, op, value}` with operators `equals, not_equals, includes, gte, lte, answered` (one rule per question, no AND/OR, no page-level rules); `normalize()` and `validateAnswers()` on the server.
- Targeting `SurveyAudience`: 12 filters (schools, regions, school types, stages, job titles, specialisations, nationalities, genders, qualifications, grade levels, subjects, grades taught) with live count, composition preview and sample.
- Templates `SurveyTemplates` (teacher TNA, leadership TNA …), analytics `SurveyAnalyzer` (response rate, per-question distributions, averages, NPS, matrix averages, segments, "signals"), CSV export (names/e-mails omitted when anonymous), `generate-needs` turns answers into individual training needs.
- Lifecycle: draft → published (recipients notified) → closed → reopened; reminders; approval sub-flow (`submit-approval`, `approve`, `return`, permission `instruments.approve`); duplicate; templates.
- Respondent side: `/me/needs-surveys` list / show / **submit** (`updateOrCreate` keyed by `survey_id + user_id` → one answer per person, **re-submission silently overwrites** while open); web `portal/Surveys.tsx` + `components/surveys/SurveyForm.tsx` (339 lines, paging with `one_per_page`, progress, welcome/thank-you, accent colour); Flutter `features/needs`.
- Studio UI: `pages/admin/needs/SurveyStudio.tsx` (243) + `QuestionEditor.tsx` (210) + `StudioDialogs.tsx` (240) + `SurveyReport.tsx` (338); shared logic `lib/surveys.ts` (398). Settings modal: anonymous, closes-at, one-per-page, progress, welcome, thank-you, accent. Actions: save, preview, publish, republish, copy link (an **authenticated** portal link).

### 3.2 Programme surveys (`survey_questions`, `survey_responses`) — post-course satisfaction gate
- Per-programme opening mode `always | manual | auto (after N hours)`, notification + read tracking, scheduled opening (`tedc:open-surveys`). Older, simpler schema, separate from 3.1.

### 3.3 Evaluation suite (`evaluation_forms`, `evaluation_assignments`, `evaluation_responses`, `evaluation_interviews`, `program_evaluation_reports`, `satisfaction_alerts`)
- Form kinds `satisfaction, impact_trainee, impact_manager, trainer_reflection, planning_evaluation, supervisor_feedback, specialist_feedback, custom`; bilingual titles; **`version`** column; **approval workflow** (submit → approve / return, re-approval when an approved form changes); `is_default`, `is_system`; settings `anonymous`, `evidence`, impact timings (45/90 days), reminders, expiry, satisfaction thresholds, `anonymous_min_responses` (results hidden until enough answers), classification `successful_continue | needs_review | weak_stop`, low-satisfaction alerts.
- Assignments by respondent type `trainee, manager, trainer, supervisor, planning_specialist, planning_member`; evidence files/links on answers; interviews; exports Excel / PDF / Word via `Reports/SurveyDocument`; comparative analysis; satisfaction ranking; the "evaluation centre" board. Web `EvaluationForms.tsx`, `EvaluationSettings.tsx`, `program/EvaluationTab.tsx`, `portal/MyEvaluations.tsx`; Flutter `features/evaluation`.

### 3.4 Also present
Polls in communities (`polls`, `poll_votes`), registration forms (`registration_forms`), question banks for exams (`questions`, `question_banks`), impact surveys and supervisor evaluations (older `000400_create_outcome_tables`).

### 3.5 Gap list against the prompt's scope (verified absent)

| Requirement | State |
|---|---|
| Anonymous **and** confidential modes with a real guarantee | ✗ `settings.anonymous` only hides name/e-mail in reports and exports; the response row still stores `user_id` (needed for the one-per-person rule) |
| Public / secure invitation links, token per invitee, open link | ✗ none for surveys (pattern exists in external forms) |
| QR code per survey / per link | ✗ not wired (library present) |
| File-upload question, Likert as a named type, instructions block, time / e-mail / phone | ✗ (`section` has a description; `scale`/`matrix` can imitate Likert) |
| Skip logic with AND/OR groups, page-level visibility, "skip to page/end" | ✗ single `show_if` per question only |
| Page validation with per-page error summary | ◐ required check exists; no per-page summary or scroll-to-error |
| Autosave / draft recovery for respondents | ✗ none (no local or server draft) |
| Published-version immutability + versioned responses | ✗ needs surveys: `update` has no status guard and no version; evaluation forms have `version` + re-approval |
| Response editing policy (never / until close / window) | ✗ implicit "overwrite while open" |
| Duplicate prevention for public/anonymous links | ✗ (n/a today — no public links) |
| Availability window (opens_at + closes_at + time zone) | ◐ `closes_at` only; publish = open |
| Training evaluation workflows (pre/post, trainer, manager impact, follow-up) | ✓ largely exists in the evaluation suite |
| Targeting by org attributes | ✓ (needs surveys) / ◐ evaluation suite targets by programme role |
| Approval workflow | ✓ both engines (different permissions) |
| Analytics dashboard | ✓ needs surveys; ✓ evaluation suite; not unified |
| Arabic PDF / Excel / CSV exports | ◐ CSV (needs), Excel/PDF/Word (evaluation); no PDF for needs surveys |
| Audit trail for survey lifecycle (publish, close, export, anonymity change) | ✗ 0 audit calls in `NeedsSurveyController` and `EvaluationService` |
| Retention policy for responses | ✗ none (`ProctoringRetention` is the only retention job; pattern reusable) |
| Optional AI (question suggestions, open-text themes) | ✗ not used for surveys; `AiGateway` ready |

## 4. Available tests

- Backend: **1,142** feature/unit tests (1,072 pass on this Windows machine; 4 failures are Windows-only OpenSSL / line-ending cases documented in CHANGELOG; CI is green on SQLite, PostgreSQL and the LDAP job).
- Survey-related today: `NeedsSurveyTest` (3), `EvaluationSuiteTest` (14), `NotificationTemplatesAndSurveyTest` (4) = **21 tests**, covering: build → target → publish → needs generation; templates; demo seeding; assignment fan-out; impact scheduling; reminders/expiry; evidence validation; hidden results under the anonymity threshold; comparative numbers; classification thresholds; report approval; alerts; exports; form approval & re-approval; the board; notification templates; survey gates.
- Web: Vitest unit tests in CI plus a Playwright smoke test (camera); a Playwright sweep of 140 routes × 10 roles was run this week from a scratch script (not in the repo).
- Not covered: conditional-logic edge cases, answer validation per type, anonymous export masking, concurrent submissions, closing while someone is answering.

## 5. Database setup

- Migrations are timestamp-ordered files in `backend/database/migrations` (survey tables in `000400`, `000800`, `002200`, `004200`). JSON columns hold questions / answers / audience / settings; both SQLite and PostgreSQL are exercised in CI, so new SQL must avoid PostgreSQL-only or SQLite-only syntax (this week's `varchar = integer` fix is the cautionary example).
- Production data lives in Supabase PostgreSQL (Singapore). **Data residency inside Qatar is not satisfied by the current hosting**; the Azure IaC in `infra/` is the planned target. This is a Ministry decision, not a code change.
- The `DataClassification` register (regenerated by `php artisan tedc:data-classification --write`) must be updated for every new PII column; `Anonymiser` masks classified columns in exports.

## 6. Authentication approach

- Local accounts (`PasswordService` with policy and lockout), Supabase Auth, OpenID Connect, SAML 2.0, LDAP/AD, MFA, an SSO-mandatory switch for staff, break-glass super admin; access + refresh tokens in `localStorage['tedc.session']`; `AuthSessions` with revoke-all; password reset added this week.
- Respondents today are always **authenticated TEDC users** (employees). The prompt's "secure invitations" and "anonymous" modes require a **second principal type** — the invitation-token holder / anonymous visitor — which does not exist. The external-forms controller shows the accepted pattern: public slug, throttles, e-mail verification code, no session.

## 7. Security concerns found

1. **Anonymity is cosmetic.** `needs_survey_responses.user_id` is stored even when `settings.anonymous = true`; the employee attribute snapshot (school, nationality, gender, stage, job title) plus `submitted_at` can re-identify a person in a small school. Any administrator with DB access, and any future report, can link answers to people.
2. **No audit trail** on survey creation, publish, close, reopen, anonymity toggle, export or needs generation (0 calls), unlike the rest of the system.
3. **Published surveys stay editable** (`PUT needs-surveys/{id}` has no status check): questions can change under existing answers, which corrupts analytics and breaks the "immutable published version" rule.
4. **Silent overwrite** of a previous answer on re-submit (`updateOrCreate`) with no history and no policy.
5. **Exports** carry names and e-mails whenever the survey is not anonymous, with no export logging and no watermark; the prompt classifies this as confidential employee data.
6. **No retention** for responses; employee snapshots persist indefinitely.
7. **Scheduling on the live host**: the 15-minute `open-surveys` and hourly evaluation jobs run once a day on Vercel; reminders and auto-openings are late by up to a day.
8. **Queue is `sync` on Vercel**: a publish to 3,000 recipients sends notifications inline in the request (risk of time-outs); fine on Azure with workers.
9. Secrets handling is sound (no secrets found in the repo; `.env` ignored; `CRON_SECRET` protects system routes). Nothing to report here beyond keeping it so.

## 8. Missing configuration (to be decided or set, not code)

| Item | Where | Why it matters |
|---|---|---|
| `TEDC_WEB_URL` / `APP_URL` on each environment | env | public survey links and QR codes embed it |
| Mail channel readiness (`MAIL_MAILER=log` by default; `ChannelSettings`) | Settings → Channels | invitations and reminders by e-mail |
| SMS / Teams channels | Settings → Integrations | optional invitation channels |
| Queue workers (not `sync`) and a real scheduler (every minute) | hosting | reminders, auto-open/close, bulk invitations, exports |
| Retention periods for responses, invitations, exports | Settings (new) | privacy rule, must be configurable |
| Anonymity threshold (`anonymous_min_responses` exists for evaluations only) | Settings (new, global default) | prevents small-group re-identification |
| AI usage approval for survey data (which model, which data classes) | Settings → AI models + policy | the prompt forbids sending confidential data to external AI without approval |
| Storage bucket + limits for file-upload answers | Settings → Files | file-upload question type |
| A `surveys.*` permission family and the roles that get it | RBAC seeder | authorization matrix |

---

## 9. Gate 1 proposal (for review — no code until approved)

### 9.1 ADR-001 — Keep the stack; add one **unified survey engine** and migrate the two existing engines onto it
- **Decision**: build `app/Domain/Surveys` (models, services, policies) as the single engine; keep the REST + React SPA + Flutter clients; **no Inertia**.
- **Why**: three engines with three schemas is the root cause of most gaps (no shared logic, anonymity, versioning, exports). The needs-survey schema (`SurveySchema`) is the most complete and becomes the base. Evaluation forms keep their workflow (kinds, assignments, impact timings, classification) but their *questions and responses* move to the engine. Programme surveys become a survey of kind `satisfaction` attached to a programme.
- **Compatibility**: existing routes keep answering during migration via thin adapters; old tables are read-only after a one-off data migration with a reversible mapping; Flutter screens keep working against the `/me/*` routes until updated.

### 9.2 Module boundaries
```
Surveys/
  Definition   survey, survey_version (immutable snapshot), question schema, logic rules, templates
  Distribution audience (reuse SurveyAudience), invitations (token per person), public links, QR, reminders, windows
  Collection   sessions (drafts), responses, answer validation, duplicate rules, edit policy, file answers
  Privacy      mode (identified | confidential | anonymous), threshold, pseudonymisation, retention jobs
  Workflow     draft → in_review → approved → published → closed → archived (+ return), re-approval on change
  Analytics    stats per question, segments, cross-tabs, response funnel, comparisons across versions
  Exports      Arabic PDF (mpdf), Excel (PhpSpreadsheet), CSV, Word; watermark + export log
  Training     evaluation kinds, assignments, impact timings, satisfaction alerts (existing code re-pointed)
  AI (opt-in)  question suggestions, open-text themes, summary — only with Settings approval and a data-class filter
```

### 9.3 Schema (ERD sketch; final DDL in Gate 2)
- `surveys` (id, kind, title_ar/en, description_ar/en, owner_id, status, privacy_mode, current_version_id, settings json, opens_at, closes_at, timezone, retention_days, created/updated).
- `survey_versions` (id, survey_id, number, questions json, logic json, pages json, published_at, published_by, checksum) — **append-only**; responses point at a version.
- `survey_distributions` (id, survey_id, channel: `audience|invitation|public|programme`, audience json, max_responses, one_per_device, requires_login, slug, token_hash for public links, qr_cached_at).
- `survey_invitations` (id, survey_id, user_id nullable, email nullable, token_hash, sent_at, reminded_at, opened_at, responded_at, expires_at).
- `survey_sessions` (id, survey_id, version_id, respondent_key, draft_answers json, page, last_seen_at, expires_at) — autosave; purged on submit or expiry.
- `survey_responses` (id, survey_id, version_id, respondent_user_id **nullable**, respondent_pseudonym (HMAC, only in confidential mode), segment_snapshot json **(only the attributes the privacy mode allows)**, answers json, status, submitted_at, edited_at, edit_count, source: `web|mobile|public|qr`).
- `survey_response_files` (response_id, question_id, storage path, mime, size, scanned_at).
- `survey_events` (audit-style domain events: published, closed, exported, anonymity_changed …) mirrored into `AuditLog`.
- Training tables (`evaluation_assignments`, `program_evaluation_reports`, `satisfaction_alerts`) stay and gain `survey_id` / `response_id` foreign keys.

### 9.4 Privacy and anonymity design (needs a human decision, see §10)
- **identified**: respondent stored; results per person visible to roles with `surveys.results.identified`.
- **confidential**: respondent stored as an HMAC pseudonym (key in the app key ring, rotated per survey); one-per-person enforced by the pseudonym; segments shown only above the threshold (default 5, configurable); the raw table never shows user ids; de-pseudonymisation is impossible without the key, which is deleted at close (+ grace).
- **anonymous**: nothing about the respondent stored; one-per-person enforced by the *invitation token being consumed*, not by identity; segment snapshot limited to the fields the author ticks, each gated by the threshold.
- Exports follow the mode; every export is logged with who, when, which columns.

### 9.5 Authorization matrix (new `surveys.*` family; mapped onto the existing roles)

| Permission | Super admin | Centre manager | Planning head | Planning specialist | Coordinator | Supervisor | Trainer | Teacher |
|---|---|---|---|---|---|---|---|---|
| surveys.view | ✓ | ✓ | ✓ | ✓ | ✓ | scope | own | – |
| surveys.manage (create/edit drafts) | ✓ | ✓ | ✓ | ✓ | ✓ | – | own kind | – |
| surveys.approve | ✓ | ✓ | ✓ | – | – | – | – | – |
| surveys.publish | ✓ | ✓ | ✓ | ✓ after approval | – | – | – | – |
| surveys.results | ✓ | ✓ | ✓ | ✓ | ✓ | scope | own | – |
| surveys.results.identified | ✓ | ✓ | – | – | – | – | – | – |
| surveys.export | ✓ | ✓ | ✓ | ✓ | – | – | – | – |
| surveys.respond | every authenticated user; public links need no account | | | | | | | |

Scopes (school / region) apply as elsewhere in TEDC.

### 9.6 Threat model (STRIDE, short form)
- **Spoofing**: public links guessable → 256-bit random token, hashed at rest, per-distribution rate limit, optional e-mail code (external-forms pattern). Invitation reuse → single-use tokens, expiry.
- **Tampering**: editing a published version → append-only versions, checksum; client-side answer manipulation → server validation per type and per logic (already in `SurveySchema`).
- **Repudiation**: no audit → domain events + `AuditLog` for every lifecycle action and export.
- **Information disclosure**: re-identification → modes above, thresholds, segment whitelist, export logs; file answers → private bucket, signed short-lived URLs, content-type and size checks, scan hook.
- **Denial of service**: open public links → throttles, max responses, closing window, one-per-device cookie (weak, documented), queue for fan-out.
- **Elevation**: results without permission → policy classes per action + scope, tested.

### 9.7 Implementation plan (gates, each with tests and a report)
- **Gate 2 — Engine core**: migrations, models, `SurveyDefinition` service (types incl. `likert`, `file_upload`, `instructions`, `time`, `email`, `phone`), logic rules (groups, page rules, skip-to), versioning, policies, permissions, audit, data-classification update. Tests first.
- **Gate 3 — Studio UI**: rebuilt `SurveyStudio` (palette, drag-order, inline editing, logic builder, page breaks, preview as respondent, bilingual fields, templates gallery) in the existing design system (Tailwind v4 tokens, RTL first).
- **Gate 4 — Distribution**: audiences, invitations, public links + QR (SVG/PNG, printable card with the Ministry crest and title), reminders, windows, embed snippet; respondent flow with autosave, page validation, resume, thank-you, edit policy, duplicate rules; mobile parity.
- **Gate 5 — Training workflows**: re-point evaluation kinds, assignments, impact timings, satisfaction alerts and reports onto the engine; data migration with dry-run and rollback.
- **Gate 6 — Analytics & exports**: unified dashboard, cross-tabs, comparisons, Arabic PDF/Excel/CSV/Word, export log.
- **Gate 7 — Privacy & operations**: retention jobs, pseudonym key lifecycle, DPIA note, settings screens, SIEM events, Vercel cron limits documented / Azure scheduler.
- **Gate 8 — AI (behind approval)**: question suggestions and open-text themes through `AiGateway`, data-class filter, opt-in per survey, audit of prompts.

### 9.8 Assumptions recorded
- A1 The Ministry's language order is Arabic first, English second, for every survey field.
- A2 One response per person per survey version is the default; editing until close is the default policy.
- A3 Respondents of public links are not employees and never receive employee attributes.
- A4 Existing needs-survey and evaluation data must be migrated, not discarded.
- A5 Hosting will move to Azure before go-live (workers, scheduler, residency); until then Vercel limits apply and are documented.

## 10. Required human decisions before Gate 2

1. **Anonymity semantics**: is the three-mode design (identified / confidential / anonymous) acceptable, and what is the minimum group size for segment results (proposed 5)?
2. **Edit policy default** and whether an edit history must be kept per response.
3. **Public links**: allowed at all for Ministry surveys? If yes, which duplicate rule (invitation-only, e-mail code, device cookie) and a maximum per survey?
4. **Retention**: default retention for responses (proposed 36 months), for invitations (90 days after close), for file answers, and whether anonymised aggregates are kept longer.
5. **External AI**: may open-text answers be sent to the configured model? Proposed: only with privacy mode = anonymous or after pseudonymisation, opt-in per survey, logged.
6. **Data residency**: confirm Supabase Singapore is acceptable for the pilot and Azure Qatar/UAE for production.
7. **Scope of migration**: migrate programme surveys and evaluation forms into the engine in this programme of work (recommended) or leave them and only replace needs surveys.
8. **Roles**: confirm the authorization matrix in §9.5.

## 11. Exact next step

On your approval of §9 and answers to §10 (the proposed defaults are used for anything left unanswered), Gate 2 starts: migrations + models + `SurveyDefinition` service with tests, on this branch, behind a feature flag `survey_studio_v2`, with the per-gate report in `docs/surveys/02-gate2-engine.md`.
