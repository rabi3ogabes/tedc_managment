# Survey Studio (ستوديو الاستبانات) — Gate 2 report: engine core

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Gate 0 / Gate 1](00-gate0-discovery.md) (all recommendations approved on 2026-10-15).

Gate 2 delivers the unified survey engine's core on the server, behind the feature flag `survey_studio_v2`: the definition model with 21 question types, condition groups, page-level rules and jumps; immutable published versions; the three privacy modes; autosaved drafts; one answer per person with an edit history; the approval and publication workflow; audit and event trails; permissions. No user interface yet (Gate 3), no public links, invitations, QR codes or file storage for answers (Gate 4), no analytics or exports (Gate 6), no retention jobs (Gate 7), no AI (Gate 8).

## 1. Files changed

New
- `backend/database/migrations/2026_01_01_006500_create_survey_engine_tables.php` — 7 tables (see §3).
- `backend/app/Models/Survey.php`, `SurveyVersion.php` (append-only: updates are refused at model level), `SurveyParticipant.php`, `SurveySession.php`, `SurveySubmission.php`, `SurveySubmissionEdit.php`, `SurveyEvent.php`.
- `backend/app/Services/Surveys/SurveyDefinition.php` — types catalogue, normalisation, checksum, settings defaults.
- `backend/app/Services/Surveys/LogicEngine.php` — condition groups, page rules, jumps; one walk shared by validation and preview.
- `backend/app/Services/Surveys/AnswerValidator.php` — per-type validation that only keeps answers to questions the respondent saw.
- `backend/app/Services/Surveys/SurveyPrivacy.php` — respondent key, HMAC pseudonym, segment snapshot, experience bands.
- `backend/app/Services/Surveys/SurveyWorkflow.php` — create, update, review, approve, return, publish (versions + enrolment + notifications), close, reopen, archive, duplicate, remind.
- `backend/app/Services/Surveys/SurveyCollector.php` — participant check, drafts, submit and edit under the privacy mode.
- `backend/app/Services/Surveys/SurveyEvents.php` — event trail mirrored into the audit log; respondent events never audited.
- `backend/app/Http/Controllers/Api/V1/Admin/SurveyController.php`, `backend/app/Http/Controllers/Api/V1/Me/MySurveyController.php`.
- `backend/tests/Feature/SurveyEngineTest.php` — 6 tests, 149 assertions.

Changed
- `backend/routes/api.php` — `admin/surveys/*` (23 routes) and `me/studio-surveys/*` (5 routes), both behind `feature:survey_studio_v2`. The parameter is `{studioSurvey}` because `{survey}` is globally bound to the legacy impact survey.
- `backend/app/Providers/AppServiceProvider.php` — explicit binding for `studioSurvey`.
- `backend/database/seeders/RolePermissionSeeder.php` — 7 new permissions (`surveys.view/manage/approve/publish/results/results.identified/export`) granted as in the approved matrix.
- `backend/config/features.php` — flag `survey_studio_v2` (on outside production, off in production).
- `backend/config/tedc.php` — `surveys` defaults: segment threshold 5, retention 1,095 days, invitation retention 90 days, draft TTL 30 days, pseudonym-key grace 30 days (all overridable by environment).
- `backend/config/data_classification.php` + `docs/security/data-classification.md` — the new columns classified (answers, segment snapshot and the identified respondent id confidential; pseudonym, pseudonym key and draft key restricted).
- `backend/lang/{ar,en}/messages.php` — `messages.surveys.*` (24 keys).
- `backend/app/Services/Notifications/NotificationCatalog.php` — `survey.invite`, `survey.reminder` (editable templates, can be switched off like every other notification).

## 2. Features implemented

- **Definition**: 21 types — section, instructions, page break, short/long text, e-mail, phone, number (min/max), date, time, single, multiple (min/max select, "other"), dropdown, yes/no, star rating, numeric scale, Likert, NPS, matrix, ranking, file upload (max files, size, accepted kinds). Bilingual titles, descriptions and option labels. Skill links kept for training-needs analysis. At most 200 questions.
- **Logic**: `visible_if` groups (all/any, 11 operators) on questions and page breaks (a hidden page hides its questions); `jumps` on single-valued questions to a later question or to the end. References may only point backwards and jumps only forwards (validated). Legacy `show_if` accepted.
- **Validation**: server-side, per type, following the same walk as the renderer: answers to hidden or skipped questions are discarded, required visible questions are enforced, matrix completeness and minimum selections checked.
- **Versions**: publishing freezes `survey_versions` (append-only, checksum); re-publishing without change creates no version; submissions and drafts are bound to a version; a draft bound to an older version is discarded on resume.
- **Workflow**: draft → in_review → approved → published → closed → published (reopen) / archived. Approval is recorded as the checksum the approver saw, so any later edit needs approval again; a user with `surveys.approve` may publish directly (recorded as a self-approval). Reopen refuses when the draft changed (publish instead). Archive never deletes.
- **Privacy modes** (approved D1): identified (user id + full segment snapshot), confidential (HMAC-SHA256 pseudonym with a per-survey key stored encrypted; only the ticked segment fields), anonymous (nothing about the person; only ticked segment fields). The mode is frozen once an answer exists.
- **One answer per person**: by user id, by pseudonym, or (anonymous) by the participation registry, which is never joined to answers.
- **Edit policy** (approved D2): editable until close with history (`survey_submission_edits`), or never; anonymous answers cannot be edited in Gate 2.
- **Autosave**: `PUT me/studio-surveys/{id}/draft` keyed by the privacy-mode respondent key (never the user id outside identified mode), 30-day TTL, deleted on submit.
- **Audience and notifications**: the existing `SurveyAudience` filters; publish enrols matching employees into `survey_participants` and notifies newcomers; reminders to those who have not answered.
- **Events and audit**: every administrative action writes `survey_events` and an `audit_logs` row with the actor; `submission_received` / `submission_edited` carry no actor, no IP and are not audited.
- **API for the studio**: catalogue (types, operators, modes, segment fields, defaults), logic preview, audience preview, versions, events.

## 3. Migrations

`surveys`, `survey_versions`, `survey_participants`, `survey_sessions`, `survey_submissions`, `survey_submission_edits`, `survey_events`. Portable SQL only (both SQLite and PostgreSQL in CI). The legacy tables (`needs_surveys`, `survey_questions`/`survey_responses` of lessons, `evaluation_*`) are untouched; `surveys.legacy_type/legacy_id` are reserved for the Gate 5 migration. Down migration drops the seven tables.

## 4. Tests executed and actual results

- `php artisan test --filter=SurveyEngineTest`: **6 passed, 149 assertions** (definition and logic rules; logic engine + validator; versions, flag and permission guards, audit trail; identified mode with drafts, edits and close; confidential and anonymous modes; preview, duplicate, archive).
- Full backend suite (`php artisan test`, SQLite, Windows): **1,148 tests, 1,078 passed, 4 failed, 54 errors, 14,773 assertions, 9.5 min**. All 58 non-passing cases are the pre-existing Windows-only ones (54 `openssl_pkey_*` errors in the SAML, LTI, push, Supabase and signature tests; `VercelEnvTest` x2, the mutual-TLS certificate byte count, the integration-guides line endings). None touches the survey engine; CI on Linux runs the same suite on push.
- Not run: the web and mobile suites (no client code changed), PostgreSQL locally (CI runs it on push).

## 5. Security-sensitive changes

- New confidential data: survey answers, segment snapshots, the identified respondent id. New restricted data: pseudonyms, the per-survey pseudonym key (encrypted at rest, hidden from every API response, test asserts it), draft respondent keys.
- Respondent events deliberately bypass the audit log (which records the signed-in user) so a confidential or anonymous answer cannot be matched to a person through TEDC's own records. This is a conscious exception to "audit everything" and is documented in `SurveyEvents`.
- The pseudonym key is created at first publish and is not yet deleted after close + grace (Gate 7 job). Until then, a database administrator holding the application key could, in principle, recompute pseudonyms. The key never leaves the server.
- Throttles: drafts 60/min, submissions 20/min per user.
- Privacy mode cannot change after the first answer; versions cannot be updated; archiving never deletes.

## 6. Known defects and limitations

- `experience_band` uses `employees.experience_years`; employees without a value have no band (no error).
- Jumps evaluate on the cleaned answer of the triggering question; an "other" free-text answer never triggers a jump (by design, documented here).
- A respondent whose draft is bound to a superseded version loses that draft silently (the next `show` returns `draft: null`). Gate 3 should tell them.
- `audience: null` publishes a survey with no participants (intended for Gate 4 public links); the admin API does not warn yet.
- `me/studio-surveys` is a temporary route name until Gate 5 retires the legacy `me/surveys`.

## 7. Requirements not yet verified (deferred to later gates)

Public and invitation links, QR codes, e-mail-code and device duplicate rules, file storage and scanning for file answers, analytics and threshold enforcement on segments, Arabic PDF/Excel/CSV exports, retention and key-deletion jobs, SIEM events, the studio and respondent UI (web and mobile), migration of the three legacy engines, AI.

## 8. Human decisions required

None new. The eight Gate 1 decisions were taken on 2026-10-15 and are implemented as defaults here. One point to confirm when convenient: the flag `survey_studio_v2` is on outside production; it stays off on the live site until Gate 4 at least.

## 9. Exact next step

Gate 3 — the studio UI in `web/`: palette and inline editor for the 21 types, drag ordering, the logic builder (groups, page rules, jumps) with live preview via `preview-logic`, bilingual fields, privacy-mode and settings panels, versions and events views, plus the respondent renderer with autosave and page validation, all behind the same flag. Report in `docs/surveys/03-gate3-studio.md`.
