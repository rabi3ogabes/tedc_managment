# Survey Studio (ستوديو الاستبانات) — Gate 5 report: the training workflows on the engine

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Gate 4](04-gate4-distribution.md).

Gate 5 moves the two form-driven legacy systems onto the engine without a big bang: the training-needs surveys and the evaluation forms (trainer reflection, planning-team evaluation, supervisor and specialist feedback, custom forms). A migration with a dry run, a batch ledger and a rollback copies people, answers and evidence; the legacy records stay, become read-only and keep their reports; the evaluation workflow (assignments, reminders, expiry, scores, results, the programme report, exports) reads answers from wherever they live; new answers to migrated forms are collected by the engine with its drafts, validation and file answers. Everything stays behind `survey_studio_v2`.

## 1. Files changed

New (backend)
- `database/migrations/2026_01_01_006900_add_survey_migration_links.php` — `survey_submissions.context_id` (the evaluation assignment an answer belongs to, so one person answers the same form once per assignment); `needs_surveys.survey_id`, `evaluation_forms.survey_id` (the engine survey that took the record over); `evaluation_assignments.survey_id / submission_id / score`; the `survey_migrations` ledger (batch, legacy type and id, survey, counts, who, when).
- `app/Models/SurveyMigration.php`.
- `app/Services/Surveys/LegacyQuestions.php` — the converter: ids, options, rows, scales, skills and `show_if` carry over as they are (the engine already accepts the legacy rule); a question that accepted evidence gets two optional companions, a file-upload question (`{id}_ev`, the form's maximum files, 10 MB, PDF / image / Office / video) and a link field (`{id}_evl`), so evidence stays a first-class answer. MIME of legacy files is inferred from the name, which the legacy table never stored.
- `app/Services/Surveys/SurveyMigrator.php` — `plan()` (the dry run: counts, warnings for records without questions or answers without a user, the batches), `run()` (one batch for everything pending or one family), `migrateNeedsSurvey()` (kind `needs`; anonymous legacy surveys become `confidential` with a pseudonym per person and no user id, others `identified`; the audience is the same structure; welcome / thank-you / accent / paging settings carried; status and window carried; a frozen version 1 for published and closed surveys; recipients become participants with their notified / reminded / responded times; answers become submissions with the legacy employee snapshot in the engine's segment fields; source `import`), `migrateForm()` (kind = form kind; approved forms are published with a version numbered like the form's; `anonymous` becomes `confidential`; every assignment gets `survey_id`; answered ones get a submission with the assignment as context, the score, evidence files as engine file rows over the same stored object and links in the link field), `rollback()` (refused while answers arrived through the engine since, unless forced; removes the engine copies, deletes only engine-era stored files, hands the legacy rows back untouched), and the audit rows for a run and a rollback.
- `app/Services/Surveys/EvaluationAnswer.php` — an engine answer shaped like a legacy evaluation response (`answers`, `score`, `submitted_at`, `evidence`, `assignment`) so results, the report and the exports read both the same way.
- `app/Console/Commands/SurveysMigrate.php` — `php artisan tedc:surveys:migrate [--dry-run] [--only=needs|evaluations] [--rollback=BATCH] [--force]`.
- `app/Http/Controllers/Api/V1/Admin/SurveyMigrationController.php` — `GET/POST admin/surveys/migration`, `POST admin/surveys/migration/rollback` (permission `surveys.manage`).
- `tests/Feature/SurveyMigrationTest.php`.

Changed (backend)
- `SurveyPrivacy::contextKey()`; `SurveyCollector::submissionFor / draftFor / saveDraftFor / submitFor()` (answers inside a context; the ordinary lookups ignore context answers); `SurveyPrivacy::experienceBand()` now uses the audience's bands, so a segment means one thing in the audience filters, the needs reports and the engine.
- `EvaluationService` — assignments carry the form's `survey_id`; `submit()` goes through the engine when the form lives there (validation, draft cleanup, file binding, score from the engine answers) and returns the assignment; `answersFor()` and `questionsFor()` give every reader one shape. `GroupEvaluationController::results`, `ProgramEvaluationService::metrics`, `SurveyExportController::form` read through them; engine evidence comes with a short-lived link.
- `MyEvaluationController` rewritten: engine-backed assignments return the legacy shape without file questions (older clients keep answering) plus an `engine` block; new `PUT me/evaluations/{assignment}/draft` and `POST me/evaluations/{assignment}/files`; JSON submit with duration and source.
- `EvaluationFormController` — a migrated form is edited and approved in the studio (`form_migrated`); `POST admin/evaluation-forms/{form}/migrate`.
- `NeedsSurveyController` — a migrated survey refuses edit, publish, remind, close, reopen (`survey_migrated`); report, export, generate-needs and the list read the engine's answers through `SurveyAnalyzer::responses()` (submissions shaped like legacy rows, segments from the snapshot, people only when the mode kept them); `POST admin/needs-surveys/{needsSurvey}/migrate`.
- `MyNeedsSurveyController` — migrated surveys leave the legacy list and answer `410 survey_migrated` with the engine id; they appear in `me/studio-surveys`.
- `IndividualNeedsService::fromEngineSubmission()` — an identified needs survey answered through the engine feeds the individual needs register as before (`MySurveyController::submit` calls it).
- Models (`NeedsSurvey`, `EvaluationForm`, `EvaluationAssignment`, `SurveySubmission`), `SurveyController` presenter (`legacy_type`, `legacy_id`, `program_id`), routes, `lang/{ar,en}/messages.php` (`surveys.migrated`, `rollback_blocked`, `batch_unknown`, `evaluation.form_migrated`), `docs/security/data-classification.md` regenerated.

New / changed (web)
- `src/pages/admin/surveys/MigrationPanel.tsx` — the panel on the surveys list (`surveys.manage`): the dry run (pending needs surveys by status with recipients and answers, pending forms with assignments and answers, how many system instruments are skipped, already migrated counts, warnings), migrate everything or one family with an explanation, the result with the batch id and skipped items, the batches with answers-since counts and a rollback that asks for a typed confirmation when answers would be lost.
- `SurveysList.tsx` (button, origin badge), `Studio.tsx` (origin badge, "legacy record" link, assignment-driven kinds show a note instead of audience filters), `EvaluationForms.tsx` (migrated forms show "open in the studio"; others a "migrate" action), `needs/SurveysTab.tsx` (migrated badge, open in the studio, migrate action, no delete), `needs/SurveyStudio.tsx` (migrated surveys are read-only here, results only, with a banner and a door to the studio), `portal/MyEvaluations.tsx` (engine-backed assignments render the engine form with autosave, file answers and the same validation), `program/EvaluationTab.tsx` (engine evidence opens by its own link), `lib/surveys.ts`, `i18n/{studio2,evalc,surveys}.ts`.

## 2. What the person can do now

- An administrator opens الترحيل إلى المحرك on the surveys list, reads what a run would do, migrates everything or one family, sees the batch, and rolls a batch back (with a typed confirmation when engine-era answers would be lost). The same from the command line with a dry run.
- A single needs survey or evaluation form can be migrated from its own page; the legacy page then shows where it went, keeps its results, exports and needs generation, and refuses edits.
- Migrated surveys appear in the studio with their origin, versions, people and answers; the evaluation kinds are assignment-driven and say so.
- A trainer, a planning member, a supervisor or a specialist answers an engine-backed form in the portal with autosave, file evidence and links; the evaluation centre, the programme report and the exports read it next to the legacy answers.

## 3. Tests executed and actual results

- `php artisan test --filter="SurveyMigrationTest"` → passed, 2 tests, 106 assertions: (1) two needs surveys (one anonymous with answers and an unanswered recipient, one identified) migrate with a dry run that changes nothing, the right privacy mode, participants, pseudonymous submissions with the segment snapshot, the converted logic; the legacy report (recipients, responses, rate, needs, band filter), export and list keep working; legacy edits and re-migration are refused; people see the surveys in the engine list only; a new identified answer through the engine creates an individual need; rollback is refused because of that answer and, forced from the command line, hands everything back with the legacy answers intact. (2) A trainer-reflection form with a legacy answer (file and link evidence) and a pending assignment migrates; the submission keeps the assignment as context, the file row points at the legacy object, the link is an answer; the legacy form refuses edits; the pending assignment is answered through the engine (definition without file questions for older clients, draft, upload, validation, score, single answer); results, the board, the report metrics and the export read both answers with evidence links; new assignments are engine-backed; rollback refused then forced from the command line restores the form.
- `EvaluationSuiteTest`, `NeedsSurveyTest`, `SurveyEngineTest`, `SurveyDistributionTest` all pass with the changes (29 tests across the five suites). One pre-existing fragile assertion in the distribution test (same-second ordering) now counts the keyless row instead.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only pre-existing warnings.
- Playwright sweep in Arabic against the local servers with the demo data: the migration panel before and after a run (1 needs survey and 4 forms; 179 participants and 131 answers moved), the engine list with origin badges, the migrated needs survey in the studio and its read-only legacy page with results, the forms page pointing to the studio, a migrated evaluation form's studio, and a trainer's engine-backed assignment in the portal.

## 4. Security-sensitive changes

- Anonymous legacy needs surveys become confidential in the engine: the person's id is dropped from the answer and replaced by the survey's HMAC pseudonym; the segment snapshot keeps only the engine's allowed fields. Evaluation forms marked anonymous hide names in results as before; an assignment is addressed to a person by nature, which the report states.
- Migration and rollback need `surveys.manage` (plus the legacy permissions for the per-record actions); both are audited (`survey.migration_run`, `survey.migration_rollback`) and every migrated survey carries a `migrated` event with its batch.
- Rollback never deletes legacy data; it deletes engine-era answers only when forced and says how many, and removes stored files only for uploads made through the engine.
- Engine-backed evaluation answers are validated by the engine; a file may only be bound by the respondent who uploaded it for that assignment (context-scoped keys).

## 5. Known limitations and decisions taken

- The three fixed-schema system instruments (trainee satisfaction ratings, trainee impact, manager impact) are not migrated: their forms are not question lists, they are consumed by the mobile app and the certificate rules, and the satisfaction alerts, classification and the board already read them. Replacing them with engine surveys of kind `satisfaction` / `impact_*` needs a decision on the mobile release and is listed below.
- The Flutter app keeps answering engine-backed evaluation forms through the legacy shape (no file questions); it cannot attach files to those forms until it adopts the engine form.
- Migrated legacy evidence files keep their stored object; their size is recorded as 0 and their MIME inferred from the name.
- Programme-level needs analytics for engine-born needs surveys (not migrated ones) still go through the legacy report only when a legacy row exists; the unified analytics come with Gate 6.

## 6. Decisions for the Ministry

1. Replace the fixed satisfaction form (ratings) with a per-programme engine survey of kind `satisfaction`, which changes the mobile app's evaluation screen.
2. When to run the migration on the live data (recommended: after Gate 6, in a maintenance window, with the dry run reviewed first).

## 7. Exact next step

Gate 6 — analytics and exports on the engine: per-survey results with segments under the threshold rule, cross-survey comparisons, Arabic PDF / Excel / CSV exports, and the needs report natively on engine surveys. Report in `docs/surveys/06-gate6-analytics.md`.

---
Full suite after Gate 5 (`php artisan test --compact`, Windows): 1163 tests, 1093 passed, 15398 assertions. The 4 failures and 54 errors are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, openssl in SamlSso / Lti / PushNotifications / SupabaseConnection / IdentitySecurity).
